<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
        'company.website'      => ['cast' => 'string', 'group' => 'company', 'label' => 'Website',             'default' => ''],
        'company.tax_id'       => ['cast' => 'string', 'group' => 'company', 'label' => 'Tax ID',              'default' => ''],
        'company.facebook_url' => ['cast' => 'string', 'group' => 'company', 'label' => 'Facebook Support Page','default' => 'https://www.facebook.com/SolarnetConnectionInstallationandServices'],
        'company.timezone'     => ['cast' => 'string', 'group' => 'company', 'label' => 'Timezone',            'default' => 'Asia/Manila'],
        'company.logo_url'     => ['cast' => 'string', 'group' => 'company', 'label' => 'Company Logo',        'default' => ''],
        'company.currency'     => ['cast' => 'string', 'group' => 'company', 'label' => 'Currency Symbol',     'default' => '₱'],

        // Billing
        'billing.vat_percent'         => ['cast' => 'float', 'group' => 'billing', 'label' => 'VAT %',                    'default' => 12.0],
        'billing.due_days'            => ['cast' => 'int',   'group' => 'billing', 'label' => 'Invoice Due (days)',       'default' => 7],
        'billing.invoice_generation_days_before_due' => ['cast' => 'int', 'group' => 'billing', 'label' => 'Generate invoice (days before due)', 'default' => 7],
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
        // Saved by the guarded Router DNS Management workflow. It is not a
        // public DNS zone and never changes the WAN/public IP configuration.
        'dns_domain' => ['cast' => 'string', 'group' => 'network', 'label' => 'Internal DNS Domain', 'default' => 'solarnet.local'],
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
                'is_readonly' => in_array($key, ['ai.model', 'company.logo_url'], true),
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
            if ($key === 'billing.invoice_generation_days_before_due' && (!is_numeric($value) || (int) $value < 0 || (int) $value > 90)) {
                return response()->json(['status' => 'error', 'message' => 'Invoice generation lead time must be between 0 and 90 days.'], 422);
            }
            if ($key === 'network.suspended_speed_kbps' && (!is_numeric($value) || (int) $value < 64 || (int) $value > 1000000)) {
                return response()->json(['status' => 'error', 'message' => 'Suspended speed must be between 64 and 1,000,000 kbps.'], 422);
            }
            if ($key === 'network.payment_reminder_url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                return response()->json(['status' => 'error', 'message' => 'Payment reminder URL must be a valid absolute URL.'], 422);
            }
            if ($key === 'dns_domain' && !$this->validInternalDnsDomain((string) $value)) {
                return response()->json(['status' => 'error', 'message' => 'Internal DNS Domain must be a valid domain such as solarnet.local or lan.solarnetconnection.com.'], 422);
            }
            if ($key === 'company.facebook_url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                return response()->json(['status' => 'error', 'message' => 'Facebook Support Page must be a valid URL.'], 422);
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

    /** Upload an approved image type for company branding. */
    public function uploadCompanyLogo(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), ['logo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048']);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Upload a PNG, JPEG, or WebP logo no larger than 2 MB.', 'errors' => $validator->errors()], 422);
        }

        $oldUrl = (string) Setting::get('company.logo_url', '');
        $path = $request->file('logo')->storeAs('company-branding', 'logo-' . Str::uuid() . '.' . $request->file('logo')->extension(), 'public');
        $url = Storage::disk('public')->url($path);
        Setting::put('company.logo_url', $url);
        if ($oldPath = $this->storagePathFromUrl($oldUrl)) Storage::disk('public')->delete($oldPath);

        return response()->json(['status' => 'success', 'message' => 'Company logo uploaded.', 'logo_url' => $url]);
    }

    public function removeCompanyLogo(): JsonResponse
    {
        $oldUrl = (string) Setting::get('company.logo_url', '');
        if ($oldPath = $this->storagePathFromUrl($oldUrl)) Storage::disk('public')->delete($oldPath);
        Setting::put('company.logo_url', '');
        return response()->json(['status' => 'success', 'message' => 'Company logo removed.']);
    }

    public function publicBranding(): JsonResponse
    {
        return response()->json(['data' => [
            'name' => Setting::get('company.name', 'Solarnet Internet'),
            'logo_url' => Setting::get('company.logo_url', ''),
            'email' => Setting::get('company.email', ''),
            'facebook_url' => Setting::get('company.facebook_url', 'https://www.facebook.com/SolarnetConnectionInstallationandServices'),
        ]]);
    }

    private function storagePathFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';
        $marker = '/storage/company-branding/';
        $position = strpos($path, $marker);
        return $position === false ? null : 'company-branding/' . substr($path, $position + strlen($marker));
    }

    private function validInternalDnsDomain(string $domain): bool
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (strlen($domain) < 3 || strlen($domain) > 253 || !str_contains($domain, '.')) return false;
        foreach (explode('.', $domain) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label) !== 1) return false;
        }
        return true;
    }
}
