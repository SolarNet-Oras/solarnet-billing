<?php

namespace Tests\Unit;

use App\Models\Customer;
use Tests\TestCase;

class CustomerBillingCycleDayTest extends TestCase
{
    public function test_configured_cycle_day_overrides_installation_anniversary(): void
    {
        $customer = new Customer(['installation_date' => '2026-08-14']);
        $customer->billing_cycle_day = 25;

        $this->assertSame(25, $customer->billingCycleDay());
    }

    public function test_existing_customer_falls_back_to_installation_anniversary(): void
    {
        $customer = new Customer(['installation_date' => '2026-08-14']);

        $this->assertSame(14, $customer->billingCycleDay());
    }
}
