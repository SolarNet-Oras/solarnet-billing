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
 * anniversary. For example: installed June 1 -> the July 1 invoice is due July 1.
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

        $dateOption = $this->option('date');
        $billingDate = $dateOption ? Carbon::createFromFormat('Y-m-d', $dateOption)->startOfDay() : now()->startOfDay();
        $dryRun = (bool) $this->option('dry-run');

        $customers = Customer::active()
            ->whereNotNull('installation_date')
            ->whereDay('installation_date', $billingDate->day)
            ->whereDate('installation_date', '<=', $billingDate)
            ->with('servicePlan')
            ->get();

        $generated = [];
        $skipped = 0;
        $errors = [];
        foreach ($customers as $customer) {
            // A customer can only receive one invoice for the same due date.
            if (Invoice::where('customer_id', $customer->id)->whereDate('due_date', $billingDate)->exists()) {
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
