<?php

namespace Tests\Unit;

use App\Services\PaymentConfirmationEmailService;
use PHPUnit\Framework\TestCase;

class PaymentConfirmationEmailServiceTest extends TestCase
{
    public function test_it_labels_customer_payment_methods_for_receipts(): void
    {
        $service = new PaymentConfirmationEmailService();

        $this->assertSame('Cash', $service->paymentMethodLabel('cash'));
        $this->assertSame('GCash / mobile wallet', $service->paymentMethodLabel('mobile_money'));
        $this->assertSame('Bank transfer', $service->paymentMethodLabel('bank_transfer'));
        $this->assertSame('Other', $service->paymentMethodLabel('other'));
    }
}
