<?php

namespace Tests\Unit;

use App\Models\Customer;
use Carbon\Carbon;
use Tests\TestCase;

class CustomerBillingCycleDayTest extends TestCase
{
    public function test_installation_anniversary_overrides_a_misaligned_legacy_cycle_day(): void
    {
        $customer = new Customer(['installation_date' => '2026-08-14']);
        $customer->billing_cycle_day = 25;

        $this->assertSame(14, $customer->billingCycleDay());
    }

    public function test_existing_customer_falls_back_to_installation_anniversary(): void
    {
        $customer = new Customer(['installation_date' => '2026-08-14']);

        $this->assertSame(14, $customer->billingCycleDay());
    }

    public function test_next_due_date_uses_the_installation_anniversary_without_creating_an_invoice(): void
    {
        $customer = new Customer(['installation_date' => '2026-08-14']);
        $customer->billing_cycle_day = 25;

        $nextDueDate = $customer->nextBillingDueDate(Carbon::parse('2026-08-19', 'Asia/Manila'));

        $this->assertSame('2026-09-14', $nextDueDate?->toDateString());
    }

    public function test_legacy_cycle_day_is_used_only_when_installation_date_is_missing(): void
    {
        $customer = new Customer(['billing_cycle_day' => 25]);

        $this->assertSame(25, $customer->billingCycleDay());
    }
}
