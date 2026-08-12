<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * Simple key/value settings for company branding, billing defaults, and AI.
 * super_admin (or anyone with 'view-settings' / 'edit-settings') can access.
 */
class SettingsController extends Controller
{
    /** Whitelist of keys the API knows about — new settings must be added here first. */
    public const SCHEMA = [
        // Company / branding
        'company.name'         => ['cast' => 'string', 'group' => 'company', 'label' => 'Company Name',        'default' => 'Solarnet Internet'],
        'company.address'      => ['cast' => 'string', 'group' => 'company', 'label' => 'Business Address',    'default' => ''],
        'company.contact'      => ['cast' => 'string', 'group' => 'company', 'label' => 'Contact Number',      'default' => ''],
        'company.email'        => ['cast' => 'string', 'group' => 'company', 'label' => 'Contact Email',       'default' => ''],
        'company.timezone'     => ['cast' => 'string', 'group' => 'company', 'label' => 'Timezone',            'default' => 'Asia/Manila'],
        'company.currency'     => ['cast' => 'string', 'group' => 'company', 'label' => 'Currency Symbol',     'default' => '₱'],

        // Billing
        'billing.vat_percent'         => ['cast' => 'float', 'group' => 'billing', 'label' => 'VAT %',                    'default' => 12.0],
        'billing.due_days'            => ['cast' => 'int',   'group' => 'billing', 'label' => 'Invoice Due (days)',       'default' => 7],
        'billing.late_fee_percent'    => ['cast' => 'float', 'group' => 'billing', 'label' => 'Late-fee %',               'default' => 5.0],
        'billing.invoice_prefix'      => ['cast' => 'string','group' => 'billing', 'label' => 'Invoice Number Prefix',    'default' => 'SLR-'],
        'billing.auto_suspend_days'   => ['cast' => 'int',   'group' => 'suspension', 'label' => 'Grace period (days overdue)', 'default' => 15],

        // AI
        'ai.enabled'          => ['cast' => 'bool',   'group' => 'ai', 'label' => 'AI Assistant enabled', 'default' => true],
        'ai.model'            => ['cast' => 'string', 'group' => 'ai', 'label' => 'OpenAI model (server setting)', 'default' => 'gpt-5.4-mini'],
        'ai.system_hint'      => ['cast' => 'string', 'group' => 'ai', 'label' => 'Extra system instructions (optional)', 'default' => ''],

        // Automation (scheduled jobs)
        'automation.enabled'                => ['cast' => 'bool',   'group' => 'automation', 'label' => 'Automations enabled (master switch)',           'default' => true],
        'automation.recurring_billing_enabled' => ['cast' => 'bool', 'group' => 'automation', 'label' => 'Generate monthly billing invoices',             'default' => true],
        'automation.auto_suspend_enabled'   => ['cast' => 'bool',   'group' => 'suspension', 'label' => 'Automatically suspend overdue customers',      'default' => true],
        'automation.reminder_days_before'   => ['cast' => 'int',    'group' => 'automation', 'label' => 'Send reminder N days before due',               'default' => 3],
        'automation.overdue_reminder_days'  => ['cast' => 'string', 'group' => 'automation', 'label' => 'Overdue follow-up days (comma-separated)',      'default' => '1,7,14'],
        'automation.backup_retention_days'  => ['cast' => 'int',    'group' => 'automation', 'label' => 'DB backup retention (days)',                    'default' => 7],

        // Network / captive portal
        'network.suspended_speed_kbps' => ['cast' => 'int',    'group' => 'suspension', 'label' => 'Suspended customer speed (kbps)', 'default' => 128],
        'network.payment_reminder_url' => ['cast' => 'string', 'group' => 'suspension', 'label' => 'Payment reminder URL', 'default' => ''],
    ];

    public function index(Request $request): JsonResponse
    {
        $data = [];
        foreach (self::SCHEMA as $key => $meta) {
            $data[] = [
                'key'         => $key,
                'value'       => $key === 'ai.model' ? config('openai.model') : Setting::get($key, $meta['default']),
                'cast'        => $meta['cast'],
                'group'       => $meta['group'],
                'label'       => $meta['label'],
                'is_readonly' => $key === 'ai.model',
            ];
        }

        // Read-only server info (never editable via UI)
        $data[] = [
            'key'         => 'ai.key_configured',
            'value'       => !empty(config('openai.api_key')),
            'cast'        => 'bool',
            'group'       => 'ai',
            'label'       => 'OPENAI_API_KEY set on server',
            'is_readonly' => true,
        ];

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'settings'         => 'required|array|min:1',
            'settings.*.key'   => 'required|string',
            'settings.*.value' => 'nullable',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $applied = [];
        foreach ($request->input('settings') as $item) {
            $key = $item['key'];
            if (!isset(self::SCHEMA[$key])) {
                return response()->json([
                    'status' => 'error',
                    'message' => "Unknown setting key: {$key}",
                ], 422);
            }
            if ($key === 'ai.model') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'OpenAI model is configured only through the server OPENAI_MODEL environment variable.',
                ], 422);
            }
            $meta = self::SCHEMA[$key];
            $value = $item['value'] ?? '';

            if ($key === 'billing.auto_suspend_days' && (!is_numeric($value) || (int) $value < 0 || (int) $value > 365)) {
                return response()->json(['status' => 'error', 'message' => 'Grace period must be between 0 and 365 days.'], 422);
            }
            if ($key === 'network.suspended_speed_kbps' && (!is_numeric($value) || (int) $value < 64 || (int) $value > 1000000)) {
                return response()->json(['status' => 'error', 'message' => 'Suspended speed must be between 64 and 1,000,000 kbps.'], 422);
            }
            if ($key === 'network.payment_reminder_url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                return response()->json(['status' => 'error', 'message' => 'Payment reminder URL must be a valid absolute URL.'], 422);
            }
            Setting::put($key, $value, $meta['cast']);
            $applied[] = $key;
        }

        // Ensure fresh reads across all workers.
        foreach ($applied as $k) Cache::forget('setting.' . $k);

        return response()->json([
            'status'  => 'success',
            'message' => 'Saved ' . count($applied) . ' setting(s)',
            'keys'    => $applied,
        ]);
    }
}
