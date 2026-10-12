<?php

namespace Tests\Feature;

use App\Models\AutomationLog;
use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class October2026InvoiceRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::create(2026, 10, 12, 12, 0, 0, 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_mode_is_read_only_and_reports_missing_invoice(): void
    {
        $customer = $this->customer('Recovery Customer', '2000000001', 13);

        $this->artisan('billing:recover-october-2026')->assertSuccessful();

        $this->assertDatabaseMissing('invoices', ['customer_id' => $customer->id]);
        $summary = AutomationLog::latest('started_at')->firstOrFail()->summary;
        $this->assertSame('audit', $summary['mode']);
        $this->assertSame(1, $summary['missing_invoices']);
        $this->assertSame('would_create', $summary['details'][0]['action']);
    }

    public function test_execution_requires_exact_confirmation(): void
    {
        $this->customer('Blocked Customer', '2000000002', 14);
        $this->artisan('billing:recover-october-2026', ['--execute' => true, '--confirm' => 'wrong'])
            ->assertFailed();
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_confirmed_recovery_is_idempotent_and_preserves_scheduled_date(): void
    {
        $customer = $this->customer('Recovered Customer', '2000000003', 19);
        $arguments = ['--execute' => true, '--confirm' => 'RECOVER OCTOBER 6-12 2026'];

        $this->artisan('billing:recover-october-2026', $arguments)->assertSuccessful();
        $this->artisan('billing:recover-october-2026', $arguments)->assertSuccessful();

        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
        $this->assertDatabaseHas('invoices', [
            'customer_id' => $customer->id,
            'due_date' => '2026-10-19',
            'recurring_cycle_date' => '2026-10-19',
            'scheduled_generation_date' => '2026-10-12',
            'generation_source' => 'AUTOMATION_RECOVERY',
        ]);
    }

    public function test_existing_manual_cycle_is_reported_and_never_modified(): void
    {
        $customer = $this->customer('Existing Customer', '2000000004', 15);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'MANUAL-EXISTING',
            'issue_date' => '2026-10-08',
            'due_date' => '2026-10-15',
            'billing_period_start' => '2026-09-15',
            'billing_period_end' => '2026-10-15',
            'generation_source' => 'manual',
            'total' => 800,
            'balance' => 800,
            'status' => 'sent',
        ]);

        $this->artisan('billing:recover-october-2026', [
            '--execute' => true,
            '--confirm' => 'RECOVER OCTOBER 6-12 2026',
        ])->assertSuccessful();

        $this->assertSame(1, Invoice::where('customer_id', $customer->id)->count());
        $this->assertSame('manual', $invoice->fresh()->generation_source);
    }

    private function customer(string $name, string $account, int $cycleDay): Customer
    {
        return Customer::create([
            'account_number' => $account,
            'full_name' => $name,
            'address' => 'Test address',
            'contact_number' => '09170000000',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => $cycleDay,
            'monthly_fee' => 800,
            'status' => 'active',
        ]);
    }
}
