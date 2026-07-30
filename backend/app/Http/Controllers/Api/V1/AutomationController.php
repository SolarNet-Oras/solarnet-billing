<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AutomationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class AutomationController extends Controller
{
    /** Whitelist of jobs a super-admin is allowed to trigger manually. */
    private const RUNNABLE = [
        AutomationLog::JOB_INVOICE_REMINDERS => 'automation:invoice-reminders',
        AutomationLog::JOB_AUTO_SUSPEND      => 'automation:auto-suspend',
        AutomationLog::JOB_DB_BACKUP         => 'automation:db-backup',
        AutomationLog::JOB_UPDATE_OVERDUE    => 'automation:update-overdue',
    ];

    /**
     * GET /api/v1/automation/logs
     * Paginated feed of every automation run.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->input('per_page', 25), 100);
        $job = $request->input('job');

        $q = AutomationLog::query()->orderByDesc('created_at');
        if ($job && isset(self::RUNNABLE[$job])) {
            $q->where('job', $job);
        }

        return response()->json([
            'success' => true,
            'data'    => $q->paginate($perPage),
        ]);
    }

    /**
     * GET /api/v1/automation/jobs
     * Lists the available jobs, their schedule, and their last run summary.
     */
    public function jobs(): JsonResponse
    {
        $schedule = [
            AutomationLog::JOB_UPDATE_OVERDUE    => 'daily 02:00',
            AutomationLog::JOB_DB_BACKUP         => 'daily 02:15',
            AutomationLog::JOB_INVOICE_REMINDERS => 'daily 08:00',
            AutomationLog::JOB_AUTO_SUSPEND      => 'daily 09:00',
        ];

        $labels = [
            AutomationLog::JOB_UPDATE_OVERDUE    => 'Update overdue invoice statuses',
            AutomationLog::JOB_DB_BACKUP         => 'PostgreSQL backup (gzipped)',
            AutomationLog::JOB_INVOICE_REMINDERS => 'Send payment reminders',
            AutomationLog::JOB_AUTO_SUSPEND      => 'Auto-suspend overdue customers',
        ];

        $data = [];
        foreach (self::RUNNABLE as $key => $cmd) {
            $last = AutomationLog::where('job', $key)->orderByDesc('created_at')->first();
            $data[] = [
                'job'      => $key,
                'label'    => $labels[$key],
                'command'  => $cmd,
                'schedule' => $schedule[$key],
                'last_run' => $last ? [
                    'id'          => $last->id,
                    'status'      => $last->status,
                    'summary'     => $last->summary,
                    'duration_ms' => $last->duration_ms,
                    'started_at'  => $last->started_at?->toIso8601String(),
                    'finished_at' => $last->finished_at?->toIso8601String(),
                    'triggered_by'=> $last->triggered_by,
                ] : null,
            ];
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/v1/automation/run/{job}
     * Triggers a job on-demand. Runs synchronously (they are all small),
     * returns the fresh AutomationLog row.
     */
    public function run(Request $request, string $job): JsonResponse
    {
        if (!isset(self::RUNNABLE[$job])) {
            return response()->json([
                'success' => false,
                'message' => "Unknown automation job: {$job}",
            ], 422);
        }

        $command = self::RUNNABLE[$job];
        $user = $request->user();

        Artisan::call($command, [
            '--triggered-by' => 'manual',
            '--user-id'      => (string) ($user?->id ?? ''),
        ]);

        $log = AutomationLog::where('job', $job)
            ->where('triggered_by', 'manual')
            ->orderByDesc('created_at')
            ->first();

        return response()->json([
            'success' => true,
            'message' => "Ran {$command}",
            'output'  => Artisan::output(),
            'log'     => $log,
        ]);
    }
}
