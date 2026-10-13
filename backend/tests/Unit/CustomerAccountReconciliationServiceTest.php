<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Services\CustomerAccountReconciliationService;
use Tests\TestCase;

class CustomerAccountReconciliationServiceTest extends TestCase
{
    private function financial(float $outstanding = 0, ?int $openInvoiceCount = null): array
    {
        return [
            'outstanding_balance' => $outstanding,
            'open_invoice_count' => $openInvoiceCount ?? ($outstanding > 0 ? 1 : 0),
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

    public function test_paid_administrator_forced_suspension_is_eligible_when_no_open_invoice_remains(): void
    {
        $customer = new Customer(['status' => 'suspended', 'suspension_source' => 'manual']);
        $result = app(CustomerAccountReconciliationService::class)->restorationEligibility($customer, $this->financial(), ['should_suspend' => false]);

        $this->assertTrue($result['eligible']);
        $this->assertTrue($result['manual_restriction']);
        $this->assertTrue($result['all_invoices_settled']);
    }

    public function test_administrator_forced_suspension_remains_when_any_open_invoice_exists(): void
    {
        $customer = new Customer(['status' => 'suspended', 'suspension_source' => 'manual']);
        $result = app(CustomerAccountReconciliationService::class)->restorationEligibility($customer, $this->financial(500, 1), ['should_suspend' => false]);

        $this->assertFalse($result['eligible']);
        $this->assertStringContainsString('open invoice balance', $result['reason']);
    }

    public function test_unknown_or_technical_hold_is_not_overridden_by_payment(): void
    {
        $customer = new Customer(['status' => 'suspended', 'suspension_source' => 'technical']);
        $result = app(CustomerAccountReconciliationService::class)->restorationEligibility($customer, $this->financial(), ['should_suspend' => false]);

        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['manual_or_technical_hold']);
    }
}
