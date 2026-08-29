<?php

namespace Tests\Unit;

use App\Console\Commands\ResendInitialInvoiceEmails;
use App\Models\Customer;
use App\Models\Invoice;
use PHPUnit\Framework\TestCase;

class ResendInitialInvoiceEmailsTest extends TestCase
{
    public function test_resend_eligibility_requires_an_invoice_email_and_positive_balance(): void
    {
        $command = new ResendInitialInvoiceEmails();

        $this->assertSame('not_found', $command->eligibilityResult(null));
        $this->assertSame('skipped_no_email', $command->eligibilityResult(
            $this->invoice(null, 800),
        ));
        $this->assertSame('skipped_no_balance', $command->eligibilityResult(
            $this->invoice('customer@example.test', 0),
        ));
        $this->assertSame('ready', $command->eligibilityResult(
            $this->invoice('customer@example.test', 800),
        ));
    }

    private function invoice(?string $email, float $balance): Invoice
    {
        $customer = new Customer([
            'full_name' => 'Selected Customer',
            'email' => $email,
        ]);
        $invoice = new Invoice(['balance' => $balance]);
        $invoice->setRelation('customer', $customer);

        return $invoice;
    }
}
