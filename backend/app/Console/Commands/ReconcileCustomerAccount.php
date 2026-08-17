<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\BillingSuspensionService;
use App\Services\CustomerAccountReconciliationService;
use Illuminate\Console\Command;

class ReconcileCustomerAccount extends Command
{
    protected $signature = 'billing:reconcile-customer
                            {customer : Customer UUID or account number}
                            {--apply : Attempt only an eligible restoration after showing the report}
                            {--dry-run : Explicitly report only (the default)}';

    protected $description = 'Safely diagnose one customer financial and service restoration state';

    public function handle(CustomerAccountReconciliationService $accounts, BillingSuspensionService $suspension): int
    {
        $identifier = trim((string) $this->argument('customer'));
        $customer = Customer::query()
            ->with(['servicePlan', 'router'])
            ->where(fn ($query) => $query->whereKey($identifier)->orWhere('account_number', $identifier))
            ->first();

        if (!$customer) {
            $this->error("No customer matched '{$identifier}'. Use the account number or full customer UUID.");
            return self::FAILURE;
        }

        $financial = $accounts->snapshot($customer);
        $schedule = $suspension->gracePeriodSchedule($customer);
        $billingState = [
            'should_suspend' => $schedule['should_suspend'],
            'outstanding_balance' => $schedule['outstanding_balance'],
            'oldest_due_date' => $schedule['oldest_due_date'],
        ];
        $eligibility = $accounts->restorationEligibility($customer, $financial, $billingState);

        $this->table(['Field', 'Value'], [
            ['Customer', "{$customer->full_name} ({$customer->account_number})"],
            ['Current service status', strtoupper($customer->status)],
            ['Suspension source', $customer->suspension_source ?: 'none'],
            ['Restoration status', $customer->restoration_status ?: 'not requested'],
            ['Financial status', strtoupper($financial['financial_status'])],
            ['Total invoiced', number_format($financial['total_invoiced'], 2)],
            ['Confirmed payments', number_format($financial['confirmed_payment_total'], 2)],
            ['Allocated invoice payments', number_format($financial['allocated_payment_total'], 2)],
            ['Available advance credit', number_format($financial['available_credit_total'], 2)],
            ['Open invoices', (string) $financial['open_invoice_count']],
            ['Outstanding balance', number_format($financial['outstanding_balance'], 2)],
            ['Suspension-eligible invoice remains', $billingState['should_suspend'] ? 'yes' : 'no'],
            ['Restoration eligible', $eligibility['eligible'] ? 'YES' : 'NO'],
            ['Reason', $eligibility['reason']],
        ]);

        if (!$this->option('apply')) {
            $this->info($eligibility['eligible']
                ? 'Dry-run: this customer is eligible. Re-run with --apply to request and verify only this restoration.'
                : 'Dry-run: no service, invoice, payment, credit, or MikroTik record was changed.');
            return self::SUCCESS;
        }

        if (!$eligibility['eligible']) {
            $this->warn('No change applied: the customer is not eligible for an automatic restoration.');
            return self::SUCCESS;
        }

        $result = $suspension->restoreCustomer(
            $customer,
            'manual_customer_reconciliation',
            false,
            $financial,
            $eligibility,
        );
        $this->line(json_encode($result, JSON_PRETTY_PRINT));

        return ($result['success'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
