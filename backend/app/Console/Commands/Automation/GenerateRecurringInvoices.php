<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Automation\AutomationRunner;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/** Generate only the cycle due exactly seven Manila calendar days later. */
class GenerateRecurringInvoices extends Command
{
    protected $signature = 'automation:generate-recurring-invoices
                            {--date= : Scheduler run date in YYYY-MM-DD (defaults to today)}
                            {--dry-run : Report only, do not create invoices}
                            {--triggered-by=schedule}
                            {--user-id=}';

    protected $description = 'Generate invoices exactly seven Manila calendar days before their due date';

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

        // Billing cycles are Philippine business dates. Do not inherit the
        // application's storage timezone (production may intentionally use
        // UTC for timestamps), or a 00:05 Manila run becomes the prior date.
        $timezone = 'Asia/Manila';
        $dateOption = $this->option('date');
        $billingDate = $dateOption
            ? Carbon::createFromFormat('Y-m-d', $dateOption, $timezone)->startOfDay()
            : now($timezone)->startOfDay();
        // This is deliberately fixed at seven days. Recovery of a missed run
        // belongs to a separately confirmed command, never this scheduler.
        $leadDays = 7;
        $dryRun = (bool) $this->option('dry-run');
        if ($dateOption && app()->environment('production') && ! $dryRun && ! $billingDate->isSameDay(now($timezone))) {
            throw new \LogicException('Production recurring billing may run only for today in Asia/Manila. Use the guarded recovery command for an approved missed date.');
        }
        $cycleDate = $billingDate->copy()->addDays($leadDays);

        $customers = Customer::query()
            ->where('status', 'active')
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

        $generated = [];
        $skipped = 0;
        $covered = 0;
        $errors = [];
        $candidates = 0;
        $skipReasons = [
            'existing_recurring_invoice' => 0,
            'company_owned_plan' => 0,
            'not_billable' => 0,
            'before_first_full_service_cycle' => 0,
            'invalid_billing_schedule' => 0,
        ];
        $ineligible = [];
        foreach ($customers as $customer) {
            if (! $customer->installation_date || ! $customer->billingCycleDay()) {
                $skipped++;
                $skipReasons['invalid_billing_schedule']++;
                $ineligible[] = [
                    'customer' => $customer->full_name,
                    'customer_id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'reason' => 'invalid_billing_schedule',
                ];
                continue;
            }
            if ($customer->installation_date->copy()->startOfDay()->gt($billingDate)) continue;
            if ($customer->installation_date->copy()->startOfDay()->gt($cycleDate)) continue;
            if (min($customer->billingCycleDay(), $cycleDate->daysInMonth) !== $cycleDate->day) continue;
            $candidates++;
            $periodStart = $cycleDate->copy()->subMonthNoOverflow()->startOfDay();
            if ($periodStart->lt($customer->installation_date->copy()->startOfDay())) {
                $skipped++;
                $skipReasons['before_first_full_service_cycle']++;
                $ineligible[] = $this->skipDetail($customer, $cycleDate, 'before_first_full_service_cycle');
                continue;
            }
            if ($customer->hasCompanyOwnedPlan()) {
                $skipped++;
                $skipReasons['company_owned_plan']++;
                $ineligible[] = $this->skipDetail($customer, $cycleDate, 'company_owned_plan');
                continue;
            }
            // A manual invoice for this exact service period also owns the
            // cycle and must prevent a second automatic charge.
            if (Invoice::where('customer_id', $customer->id)
                ->where(function ($query) use ($cycleDate, $periodStart): void {
                    $query->whereDate('recurring_cycle_date', $cycleDate)
                        ->orWhere(function ($query) use ($cycleDate, $periodStart): void {
                            $query->whereDate('due_date', $cycleDate)
                                ->whereDate('billing_period_start', $periodStart)
                                ->whereDate('billing_period_end', $cycleDate);
                        });
                })
                ->exists()) {
                $skipped++;
                $skipReasons['existing_recurring_invoice']++;
                $ineligible[] = $this->skipDetail($customer, $cycleDate, 'existing_cycle_invoice');
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
                        $periodStart,
                        $cycleDate,
                        [],
                        $billingDate,
                        $cycleDate,
                        $cycleDate,
                        'recurring',
                        $billingDate,
                    );
                    $invoices->markAsSent($invoice);
                }
                $generated[] = [
                    'customer' => $customer->full_name,
                    'customer_id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'scheduled_generation_date' => $billingDate->toDateString(),
                    'billing_cycle' => $periodStart->toDateString().' to '.$cycleDate->toDateString(),
                    'due_date' => $cycleDate->toDateString(),
                    'action' => $dryRun ? 'would_create' : 'created',
                ];
            } catch (\Throwable $e) {
                $errors[] = ['customer_id' => $customer->id, 'due_date' => $cycleDate->toDateString(), 'error' => $e->getMessage()];
            }
        }

        return [
            'run_date' => $billingDate->toDateString(),
            'scheduled_generation_date' => $billingDate->toDateString(),
            'eligible_due_date' => $cycleDate->toDateString(),
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
