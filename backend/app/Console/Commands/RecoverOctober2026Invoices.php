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

class RecoverOctober2026Invoices extends Command
{
    private const CONFIRMATION = 'RECOVER OCTOBER 6-12 2026';

    protected $signature = 'billing:recover-october-2026
                            {--execute : Create only the missing audited invoices}
                            {--confirm= : Exact execution confirmation phrase}
                            {--user-id= : Super Administrator user UUID for the audit log}';

    protected $description = 'Audit or recover invoices scheduled for October 6-12, 2026 only';

    public function handle(InvoiceService $invoiceService): int
    {
        $execute = (bool) $this->option('execute');
        if ($execute && ! hash_equals(self::CONFIRMATION, (string) $this->option('confirm'))) {
            $this->error('Execution stopped. Use --confirm="'.self::CONFIRMATION.'".');
            return self::FAILURE;
        }

        $log = AutomationRunner::run(
            AutomationLog::JOB_OCTOBER_2026_INVOICE_RECOVERY,
            $execute ? 'manual_recovery' : 'manual_audit',
            $this->option('user-id') ?: null,
            fn () => $this->recover($invoiceService, $execute),
        );

        $this->line(json_encode($log->summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $log->status === AutomationLog::STATUS_ERROR ? self::FAILURE : self::SUCCESS;
    }

    private function recover(InvoiceService $invoiceService, bool $execute): array
    {
        $timezone = 'Asia/Manila';
        $windowStart = Carbon::create(2026, 10, 6, 0, 0, 0, $timezone);
        $windowEnd = Carbon::create(2026, 10, 12, 0, 0, 0, $timezone);
        $dueStart = $windowStart->copy()->addDays(7);
        $dueEnd = $windowEnd->copy()->addDays(7);

        $customers = Customer::query()
            ->where('status', 'active')
            ->whereNotNull('installation_date')
            ->with('servicePlan')
            ->orderBy('full_name')
            ->get()
            ->filter(function (Customer $customer) use ($dueStart, $dueEnd): bool {
                $day = $customer->billingCycleDay();
                return $day !== null && $day >= $dueStart->day && $day <= $dueEnd->day;
            });

        $report = [];
        $existingCount = 0;
        $missingCount = 0;
        $createdCount = 0;
        $errors = [];

        foreach ($customers as $customer) {
            $dueDate = $dueStart->copy()->setDay($customer->billingCycleDay());
            $scheduledDate = $dueDate->copy()->subDays(7);
            $periodStart = $dueDate->copy()->subMonthNoOverflow()->startOfDay();
            $amount = round((float) ($customer->servicePlan?->price ?? $customer->monthly_fee), 2);
            $existing = $this->existingCycleInvoice($customer->id, $dueDate, $periodStart);

            $row = [
                'customer_name' => $customer->full_name,
                'customer_id' => $customer->id,
                'account_number' => $customer->account_number,
                'billing_cycle' => $periodStart->toDateString().' to '.$dueDate->toDateString(),
                'scheduled_generation_date' => $scheduledDate->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'amount' => $amount,
                'existing_invoice_status' => $existing?->status,
                'action' => null,
                'created_invoice_number' => null,
                'error' => null,
            ];

            if ($existing) {
                $existingCount++;
                $row['action'] = 'skipped_existing_invoice';
                $row['created_invoice_number'] = $existing->invoice_number;
                $report[] = $row;
                continue;
            }
            if ($scheduledDate->lt($windowStart) || $scheduledDate->gt($windowEnd)) {
                $row['action'] = 'skipped_outside_recovery_window';
                $report[] = $row;
                continue;
            }
            if ($periodStart->lt($customer->installation_date->copy()->startOfDay())) {
                $row['action'] = 'skipped_before_first_full_service_cycle';
                $report[] = $row;
                continue;
            }
            if ($customer->hasCompanyOwnedPlan()) {
                $row['action'] = 'skipped_company_owned_plan';
                $report[] = $row;
                continue;
            }
            if ($amount <= 0) {
                $row['action'] = 'skipped_invalid_amount';
                $report[] = $row;
                continue;
            }

            $missingCount++;
            if (! $execute) {
                $row['action'] = 'would_create';
                $report[] = $row;
                continue;
            }

            try {
                $result = DB::transaction(function () use ($invoiceService, $customer, $periodStart, $dueDate, $scheduledDate): array {
                    Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
                    $existing = $this->existingCycleInvoice($customer->id, $dueDate, $periodStart, true);
                    if ($existing) return ['created' => false, 'invoice' => $existing];

                    $invoice = $invoiceService->generateInvoice(
                        $customer,
                        $periodStart,
                        $dueDate,
                        [],
                        now('Asia/Manila'),
                        $dueDate,
                        $dueDate,
                        'AUTOMATION_RECOVERY',
                        $scheduledDate,
                    );
                    if ($invoice->generation_source !== 'AUTOMATION_RECOVERY') {
                        return ['created' => false, 'invoice' => $invoice];
                    }
                    if ((float) $invoice->balance > 0) {
                        $invoiceService->markAsSent($invoice);
                    } elseif ($invoice->sent_at === null) {
                        $invoice->forceFill(['sent_at' => now()])->save();
                    }
                    return ['created' => true, 'invoice' => $invoice->fresh()];
                }, 3);

                $row['created_invoice_number'] = $result['invoice']->invoice_number;
                $row['existing_invoice_status'] = $result['invoice']->status;
                $row['action'] = $result['created'] ? 'created' : 'skipped_concurrent_existing_invoice';
                if ($result['created']) $createdCount++;
                else $existingCount++;
            } catch (\Throwable $exception) {
                $row['action'] = 'error';
                $row['error'] = $exception->getMessage();
                $errors[] = ['customer_id' => $customer->id, 'error' => $exception->getMessage()];
            }
            $report[] = $row;
        }

        $verifiedRecoveryInvoices = Invoice::query()
            ->where('generation_source', 'AUTOMATION_RECOVERY')
            ->whereBetween('scheduled_generation_date', [$windowStart, $windowEnd])
            ->whereBetween('due_date', [$dueStart, $dueEnd])
            ->count();

        return [
            'mode' => $execute ? 'execute' : 'audit',
            'timezone' => $timezone,
            'recovery_window' => [$windowStart->toDateString(), $windowEnd->toDateString()],
            'eligible_due_dates' => [$dueStart->toDateString(), $dueEnd->toDateString()],
            'eligible_customers' => $customers->count(),
            'missing_invoices' => $missingCount,
            'existing_invoices' => $existingCount,
            'created_invoices' => $createdCount,
            'verified_recovery_invoices' => $verifiedRecoveryInvoices,
            'verification_passed' => count($errors) === 0,
            'errors' => $errors,
            'details' => $report,
        ];
    }

    private function existingCycleInvoice(string $customerId, Carbon $dueDate, Carbon $periodStart, bool $lock = false): ?Invoice
    {
        $query = Invoice::query()
            ->where('customer_id', $customerId)
            ->where(function ($query) use ($dueDate, $periodStart): void {
                $query->whereDate('recurring_cycle_date', $dueDate)
                    ->orWhere(function ($query) use ($dueDate, $periodStart): void {
                        $query->whereDate('due_date', $dueDate)
                            ->whereDate('billing_period_start', $periodStart)
                            ->whereDate('billing_period_end', $dueDate);
                    });
            })
            ->orderBy('created_at');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }
}
