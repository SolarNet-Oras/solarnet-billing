<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Automation\AutomationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

        $automationEnabled  = (bool) Setting::get('automation.enabled', true);
        $autoSuspendEnabled = (bool) Setting::get('automation.auto_suspend_enabled', true);
        if (!$automationEnabled || !$autoSuspendEnabled) {
            return [
                'skipped' => true,
                'reason'  => 'automation.enabled=' . ($automationEnabled ? '1' : '0')
                           . ' auto_suspend_enabled=' . ($autoSuspendEnabled ? '1' : '0'),
            ];
        }

        $graceDays = (int) Setting::get('billing.auto_suspend_days', 15);
        $cutoff    = now()->subDays($graceDays)->startOfDay();

        // Customers who: are active AND have at least one unpaid invoice whose due_date < cutoff.
        $victims = Customer::active()
            ->whereExists(function ($q) use ($cutoff) {
                $q->select(\DB::raw(1))
                  ->from('invoices')
                  ->whereColumn('invoices.customer_id', 'customers.id')
                  ->where('invoices.due_date', '<', $cutoff)
                  ->where('invoices.balance', '>', 0)
                  ->whereIn('invoices.status', ['sent', 'partial', 'overdue']);
            })
            ->get();

        $suspended = [];
        $errors    = [];

        foreach ($victims as $c) {
            try {
                if (!$dryRun) {
                    $c->status = 'suspended';
                    $c->save(); // observer -> QueueService throttles the MikroTik queue
                }
                $suspended[] = [
                    'customer_id'    => $c->id,
                    'account_number' => $c->account_number,
                    'full_name'      => $c->full_name,
                ];
                Log::info('[automation] customer auto-suspended', [
                    'customer_id' => $c->id, 'account_number' => $c->account_number,
                ]);
            } catch (\Throwable $e) {
                $errors[] = [
                    'customer_id' => $c->id,
                    'error'       => $e->getMessage(),
                ];
            }
        }

        return [
            'dry_run'        => $dryRun,
            'grace_days'     => $graceDays,
            'cutoff'         => $cutoff->toDateString(),
            'candidates'     => $victims->count(),
            'suspended'      => count($suspended),
            'errors'         => $errors,
            'details'        => $suspended,
        ];
    }
}
