<?php

namespace Tests\Feature;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SuspendedOctober2026RecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 21, 12, 0, 0, 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_audit_is_read_only_and_execution_is_idempotent(): void
    {
        $customer = $this->customer('Suspended Customer', '3000000001', 15);
        $this->artisan('billing:recover-suspended-october-2026')->assertSuccessful();
        $this->assertDatabaseCount('invoices', 0);
        $this->assertSame(1, AutomationLog::latest('started_at')->firstOrFail()->summary['missing_cycles']);

        $arguments = ['--execute' => true, '--confirm' => 'RECOVER SUSPENDED OCTOBER 2026 CYCLES'];
        $this->artisan('billing:recover-suspended-october-2026', $arguments)->assertSuccessful();
        $this->artisan('billing:recover-suspended-october-2026', $arguments)->assertSuccessful();

        $invoice = Invoice::where('customer_id', $customer->id)->sole();
        $this->assertSame('2026-09-15', $invoice->billing_period_start->toDateString());
        $this->assertSame('2026-10-15', $invoice->due_date->toDateString());
        $this->assertSame('2026-10-08', $invoice->scheduled_generation_date->toDateString());
        $this->assertSame('AUTOMATION_RECOVERY', $invoice->generation_source);
    }

    public function test_future_schedule_active_and_expired_customers_are_never_recovered(): void
    {
        $future = $this->customer('Future Suspended', '3000000002', 29);
        $active = $this->customer('Active Customer', '3000000003', 15, 'active');
        $expired = $this->customer('Expired Customer', '3000000004', 15, 'expired');

        $this->artisan('billing:recover-suspended-october-2026', [
            '--execute' => true,
            '--confirm' => 'RECOVER SUSPENDED OCTOBER 2026 CYCLES',
        ])->assertSuccessful();

        foreach ([$future, $active, $expired] as $customer) {
            $this->assertDatabaseMissing('invoices', ['customer_id' => $customer->id]);
        }
    }

    public function test_manual_invoice_for_exact_cycle_prevents_recovery(): void
    {
        $customer = $this->customer('Manual Cycle', '3000000005', 25);
        Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'MANUAL-CURRENT-CYCLE',
            'issue_date' => '2026-10-18',
            'due_date' => '2026-10-25',
            'billing_period_start' => '2026-09-25',
            'billing_period_end' => '2026-10-25',
            'generation_source' => 'manual',
            'total' => 800,
            'balance' => 800,
            'status' => 'sent',
        ]);

        $this->artisan('billing:recover-suspended-october-2026', [
            '--execute' => true,
            '--confirm' => 'RECOVER SUSPENDED OCTOBER 2026 CYCLES',
        ])->assertSuccessful();

        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
    }

    private function customer(string $name, string $account, int $cycleDay, string $status = 'suspended'): Customer
    {
        return Customer::create([
            'account_number' => $account,
            'full_name' => $name,
            'address' => 'Test address',
            'contact_number' => '09170000000',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => $cycleDay,
            'monthly_fee' => 800,
            'status' => $status,
            'suspension_source' => $status === 'suspended' ? 'automation' : null,
        ]);
    }
}
