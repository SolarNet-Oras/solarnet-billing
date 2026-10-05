<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\BillingSuspensionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingSuspensionEligibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_future_open_invoice_cannot_trigger_suspension(): void
    {
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->setGraceDays(15);
        $customer = $this->customer('Future Invoice', '2000000001');
        $this->invoice($customer, '2026-10-10', 'overdue');

        $schedule = app(BillingSuspensionService::class)->gracePeriodSchedule($customer);

        $this->assertFalse($schedule['should_suspend']);
        $this->assertNull($schedule['triggering_invoice']);
        $this->assertSame(800.0, $schedule['outstanding_balance']);
    }

    public function test_invoice_inside_grace_period_cannot_trigger_suspension(): void
    {
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->setGraceDays(15);
        $customer = $this->customer('Grace Invoice', '2000000002');
        $this->invoice($customer, '2026-09-25', 'overdue');

        $schedule = app(BillingSuspensionService::class)->gracePeriodSchedule($customer);

        $this->assertFalse($schedule['should_suspend']);
        $this->assertNull($schedule['triggering_invoice']);
    }

    public function test_invoice_past_full_grace_period_triggers_suspension(): void
    {
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->setGraceDays(15);
        $customer = $this->customer('Past Grace Invoice', '2000000003');
        $invoice = $this->invoice($customer, '2026-09-19', 'sent');

        $schedule = app(BillingSuspensionService::class)->gracePeriodSchedule($customer);

        $this->assertTrue($schedule['should_suspend']);
        $this->assertSame($invoice->id, $schedule['triggering_invoice']->id);
        $this->assertSame('2026-10-05', $schedule['suspension_at']->toDateString());
    }

    private function setGraceDays(int $days): void
    {
        Setting::put('billing.auto_suspend_days', $days, 'int');
    }

    private function customer(string $name, string $account): Customer
    {
        return Customer::create([
            'account_number' => $account,
            'full_name' => $name,
            'address' => 'Test address',
            'contact_number' => '09170000000',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => 5,
            'monthly_fee' => 800,
            'status' => 'active',
        ]);
    }

    private function invoice(Customer $customer, string $dueDate, string $status): Invoice
    {
        return Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TEST-'.$customer->account_number,
            'issue_date' => Carbon::parse($dueDate)->subDays(7)->toDateString(),
            'due_date' => $dueDate,
            'subtotal' => 800,
            'tax' => 0,
            'discount' => 0,
            'total' => 800,
            'paid_amount' => 0,
            'balance' => 800,
            'status' => $status,
        ]);
    }
}
