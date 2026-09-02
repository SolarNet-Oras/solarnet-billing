<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\BillingSmsReminderService;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CreateAugustBillingCatchUp extends Command
{
    private const CONFIRMATION = 'CREATE AUGUST CATCH-UP INVOICES';

    protected $signature = 'billing:create-august-catch-up
                            {--issue-date= : Issue date in YYYY-MM-DD, Manila time}
                            {--dry-run : Preview eligible customers without creating or sending anything}
                            {--confirm= : Exact confirmation phrase required for execution}';

    protected $description = 'Create the reviewed August 2026 catch-up invoices for due-day 1-5 customers';

    public function handle(InvoiceService $invoices, BillingSmsReminderService $sms): int
    {
        $timezone = BillingSmsReminderService::TIMEZONE;
        $issueDate = $this->option('issue-date')
            ? Carbon::createFromFormat('Y-m-d', (string) $this->option('issue-date'), $timezone)->startOfDay()
            : now($timezone)->startOfDay();
        $temporaryDueDate = $issueDate->copy()->addDays(BillingSmsReminderService::DAYS_BEFORE_DUE);

        $customers = Customer::query()
            ->active()
            ->whereDate('installation_date', '2026-08-01')
            ->with('servicePlan')
            ->orderBy('full_name')
            ->get()
            ->filter(fn (Customer $customer) => $customer->billingCycleDay() >= 1
                && $customer->billingCycleDay() <= 5
                && ! $customer->hasCompanyOwnedPlan()
                && ($customer->servicePlan || (float) $customer->monthly_fee > 0));

        $preview = $customers->map(function (Customer $customer) use ($issueDate): array {
            $cycleDate = $issueDate->copy()->startOfMonth()->day($customer->billingCycleDay());
            $existing = Invoice::query()
                ->where('customer_id', $customer->id)
                ->whereDate('recurring_cycle_date', $cycleDate)
                ->first();

            return [
                'customer' => $customer->full_name,
                'account' => $customer->account_number,
                'cycle' => $cycleDate->toDateString(),
                'amount' => number_format((float) ($customer->servicePlan?->price ?? $customer->monthly_fee), 2, '.', ''),
                'email' => filled($customer->email) ? 'available' : 'missing',
                'phone' => filled($customer->contact_number) ? 'available' : 'missing',
                'result' => $existing ? "skip {$existing->invoice_number}" : 'would_create',
            ];
        });

        $this->table(['Customer', 'Account', 'Cycle', 'Amount', 'Email', 'Phone', 'Result'], $preview->all());
        $this->line('Issue date: '.$issueDate->toDateString().' | temporary due date: '.$temporaryDueDate->toDateString());

        if ((bool) $this->option('dry-run')) {
            $this->info('Preview only. No invoice, email, SMS, payment, customer, or network record was changed.');
            return self::SUCCESS;
        }

        if (! hash_equals(self::CONFIRMATION, (string) $this->option('confirm'))) {
            $this->error('Execution stopped. Use --confirm="'.self::CONFIRMATION.'" after reviewing --dry-run.');
            return self::FAILURE;
        }

        $created = 0;
        $skipped = 0;
        $smsResults = [];
        $errors = [];

        foreach ($customers as $customer) {
            $cycleDate = $issueDate->copy()->startOfMonth()->day($customer->billingCycleDay());
            if (Invoice::query()->where('customer_id', $customer->id)->whereDate('recurring_cycle_date', $cycleDate)->exists()) {
                $skipped++;
                continue;
            }

            try {
                $invoice = $invoices->generateInvoice(
                    $customer,
                    Carbon::create(2026, 8, 1, 0, 0, 0, $timezone),
                    Carbon::create(2026, 8, 31, 0, 0, 0, $timezone),
                    [],
                    $issueDate,
                    $temporaryDueDate,
                    $cycleDate,
                    'recurring',
                );
                $invoices->markAsSent($invoice);
                $smsResult = $sms->schedule($invoice->fresh(['customer']), $issueDate);
                $smsResults[$smsResult] = ($smsResults[$smsResult] ?? 0) + 1;
                $created++;
            } catch (\Throwable $exception) {
                $errors[] = ['account' => $customer->account_number, 'error' => $exception->getMessage()];
            }
        }

        $this->info("Created {$created}; skipped existing {$skipped}; errors ".count($errors).'.');
        $this->line('SMS: '.json_encode($smsResults));
        if ($errors !== []) $this->table(['Account', 'Error'], $errors);
        if ($created > 0) {
            $this->line('Initial emails and eligible SMS were queued for the created invoices. The production worker records actual provider delivery results.');
        } else {
            $this->warn('No invoice was created, so no email or SMS was queued. Resolve the errors above, then run the dry-run again before retrying.');
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }
}
