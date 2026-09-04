<?php

namespace Tests\Unit;

use App\Models\Customer;
use Carbon\Carbon;
use Tests\TestCase;

class CustomerBillingCycleDayTest extends TestCase
{
    public function test_explicit_billing_cycle_day_overrides_the_installation_anniversary(): void
    {
        $customer = new Customer(['installation_date' => '2026-08-14']);
        $customer->billing_cycle_day = 25;

        $this->assertSame(25, $customer->billingCycleDay());
    }

    public function test_existing_customer_falls_back_to_installation_anniversary_when_no_due_day_is_set(): void
    {
        $customer = new Customer(['installation_date' => '2026-08-14']);

        $this->assertSame(14, $customer->billingCycleDay());
    }

    public function test_next_due_date_uses_the_explicit_billing_cycle_day_without_creating_an_invoice(): void
    {
        $customer = new Customer(['installation_date' => '2026-08-14']);
        $customer->billing_cycle_day = 25;

        $nextDueDate = $customer->nextBillingDueDate(Carbon::parse('2026-08-19', 'Asia/Manila'));

        $this->assertSame('2026-08-25', $nextDueDate?->toDateString());
    }

    public function test_billing_cycle_day_is_used_when_installation_date_is_missing(): void
    {
        $customer = new Customer(['billing_cycle_day' => 25]);

        $this->assertSame(25, $customer->billingCycleDay());
    }

    public function test_system_invoice_for_day_five_is_scheduled_seven_days_before_due_date(): void
    {
        $customer = new Customer(['billing_cycle_day' => 5]);
        $creationDate = Carbon::parse('2026-08-29', 'Asia/Manila');
        $dueDate = $customer->nextBillingDueDate($creationDate);

        $this->assertSame('2026-09-05', $dueDate?->toDateString());
        $this->assertSame('2026-08-29', $dueDate?->copy()->subDays(7)->toDateString());
    }

    /** @dataProvider manualInvoiceDates */
    public function test_manual_invoice_uses_the_customer_cycle_instead_of_creation_plus_seven_days(string $created, string $expectedDue): void
    {
        $customer = new Customer(['billing_cycle_day' => 5]);
        $dueDate = $customer->nextBillingDueDate(Carbon::parse($created, 'Asia/Manila'));

        $this->assertSame($expectedDue, $dueDate?->toDateString());
    }

    public static function manualInvoiceDates(): array
    {
        return [
            'before due date' => ['2026-09-01', '2026-09-05'],
            'immediately before due date' => ['2026-09-04', '2026-09-05'],
            'on due date' => ['2026-09-05', '2026-09-05'],
            'after due date' => ['2026-09-10', '2026-10-05'],
        ];
    }

    public function test_missed_system_generation_does_not_move_the_due_date(): void
    {
        $customer = new Customer(['billing_cycle_day' => 5]);

        $this->assertSame(
            '2026-09-05',
            $customer->nextBillingDueDate(Carbon::parse('2026-09-02', 'Asia/Manila'))?->toDateString(),
        );
    }

    public function test_different_cycle_day_keeps_its_own_generation_and_due_dates(): void
    {
        $customer = new Customer(['billing_cycle_day' => 15]);
        $creationDate = Carbon::parse('2026-09-08', 'Asia/Manila');
        $dueDate = $customer->nextBillingDueDate($creationDate);

        $this->assertSame('2026-09-15', $dueDate?->toDateString());
        $this->assertSame('2026-09-08', $dueDate?->copy()->subDays(7)->toDateString());
    }
}
