<?php

namespace Tests\Feature;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringInvoiceCatchUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_recovers_only_full_post_installation_cycles_and_keeps_the_forward_window(): void
    {
        $customer = $this->customer('Catch-up Customer', '1000000001', '2026-09-02', 2);

        $this->artisan('automation:generate-recurring-invoices', [
            '--date' => '2026-10-05',
            '--dry-run' => true,
            '--triggered-by' => 'manual',
        ])->assertSuccessful();

        $summary = AutomationLog::latest('started_at')->firstOrFail()->summary;
        $dueDates = collect($summary['details'])
            ->where('account_number', $customer->account_number)
            ->pluck('due_date');

        $this->assertNotContains('2026-09-02', $dueDates);
        $this->assertContains('2026-10-02', $dueDates);
        $this->assertSame(['2026-08-31', '2026-10-12'], $summary['billing_cycle_window']);
        $this->assertSame(35, $summary['catch_up_days']);
        $this->assertSame(1, $summary['skip_reasons']['before_first_full_service_cycle']);
    }

    public function test_existing_valid_cycle_is_skipped(): void
    {
        $customer = $this->customer('New Customer', '1000000002', '2026-09-02', 2);

        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-202610-TEST',
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-02',
            'billing_period_start' => '2026-09-02',
            'billing_period_end' => '2026-10-02',
            'recurring_cycle_date' => '2026-10-02',
            'generation_source' => 'recurring',
            'subtotal' => 800,
            'tax' => 0,
            'total' => 800,
            'paid_amount' => 0,
            'balance' => 800,
            'status' => 'sent',
        ]);

        $this->artisan('automation:generate-recurring-invoices', [
            '--date' => '2026-10-05',
            '--dry-run' => true,
            '--triggered-by' => 'manual',
        ])->assertSuccessful();

        $summary = AutomationLog::latest('started_at')->firstOrFail()->summary;
        $details = collect($summary['details'])
            ->where('account_number', $customer->account_number);

        $this->assertCount(0, $details);
        $this->assertSame(1, $summary['skip_reasons']['existing_recurring_invoice']);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_suspended_subscriber_remains_eligible_for_monthly_billing(): void
    {
        $customer = $this->customer('Suspended Customer', '1000000003', '2026-09-05', 5);
        $customer->update(['status' => 'suspended']);

        $this->artisan('automation:generate-recurring-invoices', [
            '--date' => '2026-10-05',
            '--dry-run' => true,
            '--triggered-by' => 'manual',
        ])->assertSuccessful();

        $summary = AutomationLog::latest('started_at')->firstOrFail()->summary;
        $dueDates = collect($summary['details'])
            ->where('account_number', $customer->account_number)
            ->pluck('due_date');

        $this->assertContains('2026-10-05', $dueDates);
    }

    public function test_new_customer_is_not_back_billed_for_service_before_installation(): void
    {
        $customer = $this->customer('Recently Installed', '1000000004', '2026-09-03', 5);

        $this->artisan('automation:generate-recurring-invoices', [
            '--date' => '2026-10-05',
            '--dry-run' => true,
            '--triggered-by' => 'manual',
        ])->assertSuccessful();

        $summary = AutomationLog::latest('started_at')->firstOrFail()->summary;
        $dueDates = collect($summary['details'])
            ->where('account_number', $customer->account_number)
            ->pluck('due_date');

        $this->assertNotContains('2026-09-05', $dueDates);
        $this->assertContains('2026-10-05', $dueDates);
        $this->assertContains(
            'before_first_full_service_cycle',
            collect($summary['ineligible_details'])
                ->where('account_number', $customer->account_number)
                ->pluck('reason')
        );
    }

    private function customer(string $name, string $account, string $installed, int $cycleDay): Customer
    {
        return Customer::create([
            'account_number' => $account,
            'full_name' => $name,
            'address' => 'Test address',
            'contact_number' => '09170000000',
            'installation_date' => $installed,
            'billing_cycle_day' => $cycleDay,
            'monthly_fee' => 800,
            'status' => 'active',
        ]);
    }
}
