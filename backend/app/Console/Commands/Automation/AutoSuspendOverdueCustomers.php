<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Services\Automation\AutomationRunner;
use App\Services\BillingSuspensionService;
use Illuminate\Console\Command;

/**
 * Auto-suspend active customers whose oldest unpaid invoice is more than
 * `billing.auto_suspend_days` days overdue.
 *
 * When status flips to 'suspended', CustomerObserver::updated fires and
 * QueueService throttles the customer's MikroTik queue automatically. So this
 * command does NOT need to touch MikroTik directly.
 */
class AutoSuspendOverdueCustomers extends Command
{
    protected $signature = 'automation:auto-suspend
                            {--dry-run : Report only, do not actually suspend}
                            {--triggered-by=schedule}
                            {--user-id=}';

    protected $description = 'Suspend customers whose overdue balance exceeds the grace period';

    public function handle(): int
    {
        $log = AutomationRunner::run(
            AutomationLog::JOB_AUTO_SUSPEND,
            (string) $this->option('triggered-by'),
            $this->option('user-id') ?: null,
            fn () => $this->doWork()
        );

        $this->line("Job: {$log->job}  status: {$log->status}  duration: {$log->duration_ms}ms");
        $this->line(json_encode($log->summary, JSON_PRETTY_PRINT));

        return $log->status === AutomationLog::STATUS_ERROR ? 1 : 0;
    }

    protected function doWork(): array
    {
        $dryRun = (bool) $this->option('dry-run');
        $service = app(BillingSuspensionService::class);

        if ($dryRun) {
            return [
                'dry_run' => true,
                'message' => 'Dry-run mode is now handled by the service-level reconciliation logic.',
            ];
        }

        return $service->syncExpiredCustomers();
    }
}
