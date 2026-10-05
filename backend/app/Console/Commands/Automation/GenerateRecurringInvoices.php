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
 * Creates one sent invoice each month on the customer's configured billing
 * anniversary. Older records still fall back to installation-day.
 */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'automation:generate-recurring-invoices
                            {--date= : Scheduler run date in YYYY-MM-DD (defaults to today)}
                            {--dry-run : Report only, do not create invoices}
                            {--triggered-by=schedule}
                            {--user-id=}';

    protected $description = 'Generate monthly invoices on each customer billing-cycle anniversary';

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
        $leadDays = max(0, min(90, (int) Setting::get('billing.invoice_generation_days_before_due', 7)));
        // Revisit recent cycle dates on every run. The recurring-cycle check
        // makes this idempotent while repairing invoices missed during cron
        // downtime, deployments, or stale scheduler locks.
        $catchUpDays = max(
            $leadDays,
            min(90, (int) Setting::get('billing.invoice_generation_catch_up_days', 35)),
        );
        $dryRun = (bool) $this->option('dry-run');

        // Suspension restricts network access; it does not terminate the
        // subscription. Suspended subscribers must continue receiving their
        // monthly invoices so their account can be settled and restored.
        $customers = Customer::query()
            ->whereIn('status', ['active', 'suspended'])
            ->whereNotNull('installation_date')
            ->whereDate('installation_date', '<=', $billingDate)
            ->with('servicePlan')
            ->get();
        /*
            // An installation on the 29th–31st bills on the final valid day of
            // a shorter month. This is the normal anniversary rule and avoids
            // silently skipping customers in February.
            ->filter(fn (Customer $customer) => min(
                $customer->billingCycleDay(),
                $cycleDate->daysInMonth,
            ) === $cycleDate->day);
        */

        $cycleDates = collect(range(-$catchUpDays, $leadDays))
            ->map(fn (int $offset) => $billingDate->copy()->addDays($offset));

        $generated = [];
        $skipped = 0;
        $covered = 0;
        $errors = [];
        $candidates = 0;
        $skipReasons = [
            'existing_recurring_invoice' => 0,
            'company_owned_plan' => 0,
            'not_billable' => 0,
        ];
        $ineligible = [];
        foreach ($cycleDates as $cycleDate) {
          foreach ($customers as $customer) {
            if ($customer->installation_date->copy()->startOfDay()->gt($cycleDate)) continue;
            if (min($customer->billingCycleDay(), $cycleDate->daysInMonth) !== $cycleDate->day) continue;
            $candidates++;
            if ($customer->hasCompanyOwnedPlan()) {
                $skipped++;
                $skipReasons['company_owned_plan']++;
                $ineligible[] = $this->skipDetail($customer, $cycleDate, 'company_owned_plan');
                continue;
            }
            // The recurring-cycle key is authoritative. Do not use a generic
            // due date here: an early/manual invoice may legitimately share it.
            if (Invoice::where('customer_id', $customer->id)
                ->whereDate('recurring_cycle_date', $cycleDate)
                ->exists()) {
                $skipped++;
                $skipReasons['existing_recurring_invoice']++;
                continue;
            }

            // Do not generate an empty invoice for a client without a billable plan/fee.
            if (!$customer->servicePlan && (float) $customer->monthly_fee <= 0) {
                $skipped++;
                $skipReasons['not_billable']++;
                $ineligible[] = $this->skipDetail($customer, $cycleDate, 'not_billable');
                continue;
            }

            try {
                if (!$dryRun) {
                    $invoice = $invoices->generateInvoice(
                        $customer,
                        $cycleDate->copy()->subMonthNoOverflow(),
                        $cycleDate,
                        [],
                        $billingDate,
                        $cycleDate,
                        $cycleDate,
                        'recurring',
                    );
                    $invoices->markAsSent($invoice);
                }
                $generated[] = ['customer' => $customer->full_name, 'account_number' => $customer->account_number, 'due_date' => $cycleDate->toDateString()];
            } catch (\Throwable $e) {
                $errors[] = ['customer_id' => $customer->id, 'due_date' => $cycleDate->toDateString(), 'error' => $e->getMessage()];
            }
          }
        }

        return [
            'run_date' => $billingDate->toDateString(),
            'billing_cycle_window' => [$billingDate->copy()->subDays($catchUpDays)->toDateString(), $billingDate->copy()->addDays($leadDays)->toDateString()],
            'catch_up_days' => $catchUpDays,
            'lead_days' => $leadDays,
            'dry_run' => $dryRun,
            'candidates' => $candidates,
            'generated' => count($generated),
            'covered_by_advance' => $covered,
            'skipped' => $skipped,
            'skip_reasons' => $skipReasons,
            'ineligible_details' => $ineligible,
            'errors' => $errors,
            'details' => $generated,
        ];
    }

    private function skipDetail(Customer $customer, Carbon $cycleDate, string $reason): array
    {
        return [
            'customer' => $customer->full_name,
            'account_number' => $customer->account_number,
            'due_date' => $cycleDate->toDateString(),
            'reason' => $reason,
        ];
    }
}
