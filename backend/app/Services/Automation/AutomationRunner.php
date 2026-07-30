<?php

namespace App\Services\Automation;

use App\Models\AutomationLog;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin helper that wraps a callable, times it, records an AutomationLog row.
 * Every scheduled command uses this so admins get a full audit trail.
 */
class AutomationRunner
{
    /**
     * @param string        $job           One of AutomationLog::JOB_*
     * @param string        $triggeredBy   'schedule' or 'manual'
     * @param string|null   $userId        UUID of the user who triggered it (if manual)
     * @param callable      $work          function(): array — must return summary array; may throw
     */
    public static function run(
        string $job,
        string $triggeredBy,
        ?string $userId,
        callable $work
    ): AutomationLog {
        $startedAt = now();
        $t0 = microtime(true);
        $status = AutomationLog::STATUS_SUCCESS;
        $summary = [];

        try {
            $summary = $work();
            if (!empty($summary['errors']) && is_array($summary['errors']) && count($summary['errors']) > 0) {
                $status = AutomationLog::STATUS_PARTIAL;
            }
        } catch (Throwable $e) {
            $status = AutomationLog::STATUS_ERROR;
            $summary = [
                'error' => $e->getMessage(),
                'file'  => basename($e->getFile()) . ':' . $e->getLine(),
            ];
            Log::error("[automation:{$job}] failed", ['error' => $e->getMessage()]);
        }

        $durationMs = (int) round((microtime(true) - $t0) * 1000);

        return AutomationLog::create([
            'job'                  => $job,
            'status'               => $status,
            'summary'              => $summary,
            'duration_ms'          => $durationMs,
            'triggered_by'         => $triggeredBy,
            'triggered_by_user_id' => $userId,
            'started_at'           => $startedAt,
            'finished_at'          => now(),
        ]);
    }
}
