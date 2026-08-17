<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Services\CustomerAccountReconciliationService;
use Tests\TestCase;

class CustomerAccountReconciliationServiceTest extends TestCase
{
    private function financial(float $outstanding = 0): array
    {
        return [
            'outstanding_balance' => $outstanding,
            'financial_status' => $outstanding > 0 ? 'partial' : 'paid',
        ];
    }

    public function test_paid_automatically_suspended_customer_is_eligible_for_restoration(): void
    {
        $customer = new Customer(['status' => 'suspended', 'suspension_source' => 'automation']);
        $result = app(CustomerAccountReconciliationService::class)->restorationEligibility($customer, $this->financial(), ['should_suspend' => false]);

        $this->assertTrue($result['eligible']);
        $this->assertTrue($result['automated_restriction']);
    }

    public function test_partial_payment_that_leaves_a_suspension_eligible_invoice_is_not_restored(): void
    {
        $customer = new Customer(['status' => 'suspended', 'suspension_source' => 'automation']);
        $result = app(CustomerAccountReconciliationService::class)->restorationEligibility($customer, $this->financial(1000), ['should_suspend' => true]);

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('grace period', $result['reason']);
    }

    public function test_paid_manual_or_technical_hold_is_not_overridden_by_payment(): void
    {
        $customer = new Customer(['status' => 'suspended', 'suspension_source' => 'manual']);
        $result = app(CustomerAccountReconciliationService::class)->restorationEligibility($customer, $this->financial(), ['should_suspend' => false]);

        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['manual_or_technical_hold']);
    }
}
