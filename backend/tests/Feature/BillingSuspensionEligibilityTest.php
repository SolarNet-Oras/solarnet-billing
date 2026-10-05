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
        $invoice = $this->invoice($customer, '2026-10-10', 'overdue');

        $schedule = app(BillingSuspensionService::class)->gracePeriodSchedule($customer);

        $this->assertFalse($schedule['should_suspend']);
        $this->assertSame($invoice->id, $schedule['triggering_invoice']->id);
        $this->assertSame(800.0, $schedule['outstanding_balance']);
    }

    public function test_invoice_inside_grace_period_cannot_trigger_suspension(): void
    {
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->setGraceDays(15);
        $customer = $this->customer('Grace Invoice', '2000000002');
        $invoice = $this->invoice($customer, '2026-09-25', 'overdue');

        $schedule = app(BillingSuspensionService::class)->gracePeriodSchedule($customer);

        $this->assertFalse($schedule['should_suspend']);
        $this->assertSame($invoice->id, $schedule['triggering_invoice']->id);
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

    public function test_five_complete_grace_days_are_honored_before_suspension(): void
    {
        $this->setGraceDays(5);
        $customer = $this->customer('Five Day Grace', '2000000004');
        $this->invoice($customer, '2026-10-01', 'overdue');

        Carbon::setTestNow('2026-10-06 23:59:59');
        $duringGrace = app(BillingSuspensionService::class)
            ->gracePeriodSchedule($customer);

        $this->assertFalse($duringGrace['should_suspend']);
        $this->assertSame('2026-10-06', $duringGrace['grace_period_end']->toDateString());

        Carbon::setTestNow('2026-10-07 00:00:00');
        $afterGrace = app(BillingSuspensionService::class)
            ->gracePeriodSchedule($customer);

        $this->assertTrue($afterGrace['should_suspend']);
        $this->assertSame('2026-10-07', $afterGrace['suspension_at']->toDateString());
    }

    public function test_late_generated_catch_up_invoice_gets_five_days_after_issue(): void
    {
        Carbon::setTestNow('2026-10-05 08:00:00');
        $this->setGraceDays(5);
        $customer = $this->customer('Late Catch Up', '2000000005');
        $invoice = $this->invoice(
            $customer,
            '2026-09-12',
            'overdue',
            '2026-10-05',
        );

        $schedule = app(BillingSuspensionService::class)
            ->gracePeriodSchedule($customer);

        $this->assertSame($invoice->id, $schedule['triggering_invoice']->id);
        $this->assertSame('2026-09-12', $schedule['oldest_due_date']->toDateString());
        $this->assertSame('2026-10-06', $schedule['grace_period_start']->toDateString());
        $this->assertSame('2026-10-10', $schedule['grace_period_end']->toDateString());
        $this->assertSame('2026-10-11', $schedule['suspension_at']->toDateString());
        $this->assertFalse($schedule['should_suspend']);
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

    private function invoice(
        Customer $customer,
        string $dueDate,
        string $status,
        ?string $issueDate = null,
    ): Invoice
    {
        $due = Carbon::parse($dueDate);

        return Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-TEST-'.$customer->account_number,
            'issue_date' => $issueDate ?? $due->copy()->subDays(7)->toDateString(),
            'due_date' => $dueDate,
            'billing_period_start' => $due->copy()->subMonthNoOverflow()->toDateString(),
            'billing_period_end' => $dueDate,
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
