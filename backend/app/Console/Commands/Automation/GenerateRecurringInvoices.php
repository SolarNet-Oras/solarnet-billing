<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Automation\AutomationRunner;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Creates one sent invoice each month on the customer's installation-day
 * anniversary. Due dates use the configurable billing.due_days setting.
 */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'automation:generate-recurring-invoices
                            {--date= : Billing date in YYYY-MM-DD (defaults to today)}
                            {--dry-run : Report only, do not create invoices}
                            {--triggered-by=schedule}
                            {--user-id=}';

    protected $description = 'Generate monthly invoices on each customer installation-date anniversary';

    public function handle(InvoiceService $invoices): int
    {
        $log = AutomationRunner::run(
            AutomationLog::JOB_RECURRING_INVOICES,
            (string) $this->option('triggered-by'),
            $this->option('user-id') ?: null,
            fn () => $this->doWork($invoices)
        );

        $this->line("Job: {$log->job}  status: {$log->status}  duration: {$log->duration_ms}ms");
        $this->line(json_encode($log->summary, JSON_PRETTY_PRINT));

        return $log->status === AutomationLog::STATUS_ERROR ? 1 : 0;
    }

    private function doWork(InvoiceService $invoices): array
    {
        if (!(bool) Setting::get('automation.enabled', true) || !(bool) Setting::get('automation.recurring_billing_enabled', true)) {
            return ['skipped' => true, 'reason' => 'Recurring billing automation is disabled'];
        }

        $timezone = config('app.timezone', 'Asia/Manila');
        $dateOption = $this->option('date');
        $billingDate = $dateOption
            ? Carbon::createFromFormat('Y-m-d', $dateOption, $timezone)->startOfDay()
            : now($timezone)->startOfDay();
        $dryRun = (bool) $this->option('dry-run');

        $customers = Customer::active()
            ->whereNotNull('installation_date')
            ->whereDate('installation_date', '<=', $billingDate)
            ->with('servicePlan')
            ->get()
            // An installation on the 29th–31st bills on the final valid day of
            // a shorter month. This is the normal anniversary rule and avoids
            // silently skipping customers in February.
            ->filter(fn (Customer $customer) => min(
                $customer->installation_date->day,
                $billingDate->daysInMonth,
            ) === $billingDate->day);

        $generated = [];
        $skipped = 0;
        $errors = [];
        foreach ($customers as $customer) {
            // The recurring-cycle key is authoritative. Do not use a generic
            // due date here: an early/manual invoice may legitimately share it.
            if (Invoice::where('customer_id', $customer->id)
                ->whereDate('recurring_cycle_date', $billingDate)
                ->exists()) {
                $skipped++;
                continue;
            }

            // Do not generate an empty invoice for a client without a billable plan/fee.
            if (!$customer->servicePlan && (float) $customer->monthly_fee <= 0) {
                $skipped++;
                continue;
            }

            try {
                if (!$dryRun) {
                    $invoice = $invoices->generateInvoice(
                        $customer,
                        $billingDate->copy()->subMonthNoOverflow(),
                        $billingDate,
                        [],
                        $billingDate,
                        $billingDate->copy()->addDays((int) Setting::get('billing.due_days', 7)),
                        $billingDate,
                    );
                    $invoices->markAsSent($invoice);
                }
                $generated[] = ['customer' => $customer->full_name, 'account_number' => $customer->account_number];
            } catch (\Throwable $e) {
                $errors[] = ['customer_id' => $customer->id, 'error' => $e->getMessage()];
            }
        }

        return [
            'billing_date' => $billingDate->toDateString(),
            'dry_run' => $dryRun,
            'candidates' => $customers->count(),
            'generated' => count($generated),
            'skipped' => $skipped,
            'errors' => $errors,
            'details' => $generated,
        ];
    }
}
