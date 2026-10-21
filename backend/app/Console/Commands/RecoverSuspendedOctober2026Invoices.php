<?php

namespace App\Console\Commands;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Automation\AutomationRunner;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** One-time guarded repair for October cycles skipped solely by suspension. */
class RecoverSuspendedOctober2026Invoices extends Command
{
    private const CONFIRMATION = 'RECOVER SUSPENDED OCTOBER 2026 CYCLES';

    protected $signature = 'billing:recover-suspended-october-2026
                            {--execute : Create only verified missing cycles}
                            {--confirm= : Exact execution confirmation phrase}
                            {--user-id= : Super Administrator user UUID for the audit log}';

    protected $description = 'Audit or recover missing October 2026 cycles for suspended subscriptions through October 21';

    public function handle(InvoiceService $invoices): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && !hash_equals(self::CONFIRMATION, (string) $this->option('confirm'))) {
            $this->error('Execution stopped. Use --confirm="'.self::CONFIRMATION.'".');
            return self::FAILURE;
        }

        $log = AutomationRunner::run(
            AutomationLog::JOB_SUSPENDED_OCTOBER_2026_RECOVERY,
            $execute ? 'manual_recovery' : 'manual_audit',
            $this->option('user-id') ?: null,
            fn () => $this->recover($invoices, $execute),
        );

        $this->line(json_encode($log->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $log->status === AutomationLog::STATUS_ERROR ? self::FAILURE : self::SUCCESS;
    }

    private function recover(InvoiceService $invoices, bool $execute): array
    {
        $timezone = 'Asia/Manila';
        $month = Carbon::create(2026, 10, 1, 0, 0, 0, $timezone);
        $cutoff = Carbon::create(2026, 10, 21, 0, 0, 0, $timezone);
        $report = [];
        $missing = $created = $existing = 0;
        $errors = [];

        $customers = Customer::query()
            ->where('status', 'suspended')
            ->whereNotNull('installation_date')
            ->with('servicePlan')
            ->orderBy('full_name')
            ->get();

        foreach ($customers as $customer) {
            $cycleDay = $customer->billingCycleDay();
            if (!$cycleDay) continue;

            $dueDate = $month->copy()->day(min($cycleDay, $month->daysInMonth));
            $scheduledDate = $dueDate->copy()->subDays(7);
            if ($scheduledDate->gt($cutoff)) continue;

            $periodStart = $dueDate->copy()->subMonthNoOverflow()->startOfDay();
            $amount = round((float) ($customer->servicePlan?->price ?? $customer->monthly_fee), 2);
            $cycleInvoice = $this->existingCycleInvoice($customer->id, $dueDate, $periodStart);
            $row = [
                'customer' => $customer->full_name,
                'customer_id' => $customer->id,
                'account_number' => $customer->account_number,
                'billing_cycle' => $periodStart->toDateString().' to '.$dueDate->toDateString(),
                'scheduled_generation_date' => $scheduledDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'amount' => $amount,
                'action' => null,
                'invoice_number' => $cycleInvoice?->invoice_number,
                'error' => null,
            ];

            if ($cycleInvoice) {
                $existing++;
                $row['action'] = 'skipped_existing_cycle';
            } elseif ($customer->hasCompanyOwnedPlan()) {
                $row['action'] = 'skipped_company_owned_plan';
            } elseif ($amount <= 0) {
                $row['action'] = 'skipped_not_billable';
            } elseif ($periodStart->lt($customer->installation_date->copy()->startOfDay())) {
                $row['action'] = 'skipped_before_first_full_cycle';
            } else {
                $missing++;
                if (!$execute) {
                    $row['action'] = 'would_create';
                } else {
                    try {
                        $result = DB::transaction(function () use ($invoices, $customer, $periodStart, $dueDate, $scheduledDate): array {
                            Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                            $found = $this->existingCycleInvoice($customer->id, $dueDate, $periodStart, true);
                            if ($found) return ['created' => false, 'invoice' => $found];

                            $invoice = $invoices->generateInvoice(
                                $customer, $periodStart, $dueDate, [], now('Asia/Manila'),
                                $dueDate, $dueDate, 'AUTOMATION_RECOVERY', $scheduledDate,
                            );
                            if ((float) $invoice->balance > 0) $invoices->markAsSent($invoice);
                            elseif ($invoice->sent_at === null) $invoice->forceFill(['sent_at' => now()])->save();

                            return ['created' => $invoice->generation_source === 'AUTOMATION_RECOVERY', 'invoice' => $invoice->fresh()];
                        }, 3);
                        $row['invoice_number'] = $result['invoice']->invoice_number;
                        $row['action'] = $result['created'] ? 'created' : 'skipped_concurrent_existing_cycle';
                        if ($result['created']) $created++;
                        else $existing++;
                    } catch (\Throwable $exception) {
                        $row['action'] = 'error';
                        $row['error'] = $exception->getMessage();
                        $errors[] = ['customer_id' => $customer->id, 'error' => $exception->getMessage()];
                    }
                }
            }
            $report[] = $row;
        }

        $verifiedRecoveryCycles = Invoice::query()
            ->where('generation_source', 'AUTOMATION_RECOVERY')
            ->whereBetween('scheduled_generation_date', ['2026-09-24', $cutoff->toDateString()])
            ->whereBetween('due_date', ['2026-10-01', '2026-10-28'])
            ->whereHas('customer', fn ($query) => $query->where('status', 'suspended'))
            ->count();

        return [
            'mode' => $execute ? 'execute' : 'audit',
            'timezone' => $timezone,
            'customer_status' => 'suspended',
            'cycle_month' => '2026-10',
            'scheduled_through' => $cutoff->toDateString(),
            'customers_examined' => $customers->count(),
            'missing_cycles' => $missing,
            'existing_cycles' => $existing,
            'created_cycles' => $created,
            'verified_recovery_cycles' => $verifiedRecoveryCycles,
            'verification_passed' => $errors === [],
            'errors' => $errors,
            'details' => $report,
        ];
    }

    private function existingCycleInvoice(string $customerId, Carbon $dueDate, Carbon $periodStart, bool $lock = false): ?Invoice
    {
        $query = Invoice::query()->where('customer_id', $customerId)
            ->where(function ($query) use ($dueDate, $periodStart): void {
                $query->whereDate('recurring_cycle_date', $dueDate)
                    ->orWhere(function ($query) use ($dueDate, $periodStart): void {
                        $query->whereDate('due_date', $dueDate)
                            ->whereDate('billing_period_start', $periodStart)
                            ->whereDate('billing_period_end', $dueDate);
                    });
            })->orderBy('created_at');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
