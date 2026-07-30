<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Services\Automation\AutomationRunner;
use App\Services\InvoiceService;
use Illuminate\Console\Command;

/**
 * Flip 'sent' invoices past due_date to 'overdue'.
 * Wraps InvoiceService::updateOverdueInvoices() and records an automation log
 * so admins can see it ran nightly.
 */
class UpdateOverdueInvoices extends Command
{
    protected $signature = 'automation:update-overdue
                            {--triggered-by=schedule}
                            {--user-id=}';

    protected $description = 'Flip past-due sent invoices to overdue status';

    public function handle(InvoiceService $svc): int
    {
        $log = AutomationRunner::run(
            AutomationLog::JOB_UPDATE_OVERDUE,
            (string) $this->option('triggered-by'),
            $this->option('user-id') ?: null,
            function () use ($svc) {
                $count = $svc->updateOverdueInvoices();
                return ['flipped_to_overdue' => $count];
            }
        );

        $this->line("Job: {$log->job}  status: {$log->status}  duration: {$log->duration_ms}ms");
        $this->line(json_encode($log->summary, JSON_PRETTY_PRINT));

        return $log->status === AutomationLog::STATUS_ERROR ? 1 : 0;
    }
}
