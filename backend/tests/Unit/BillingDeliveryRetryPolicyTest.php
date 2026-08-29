<?php

namespace Tests\Unit;

use App\Jobs\SendBillingSmsReminder;
use App\Jobs\SendInitialInvoiceEmail;
use App\Models\Invoice;
use Tests\TestCase;

class BillingDeliveryRetryPolicyTest extends TestCase
{
    public function test_email_and_sms_each_receive_one_automatic_retry(): void
    {
        $email = new SendInitialInvoiceEmail('invoice-id');
        $sms = new SendBillingSmsReminder('notification-id');

        $this->assertSame(2, $email->tries);
        $this->assertSame([60], $email->backoff);
        $this->assertSame(2, $sms->tries);
        $this->assertSame([60], $sms->backoff);
    }

    public function test_invoice_email_audit_fields_have_safe_types(): void
    {
        $invoice = new Invoice([
            'initial_email_attempt_count' => '1',
            'initial_email_last_attempt_at' => '2026-08-29 10:00:00',
            'initial_email_sent_at' => '2026-08-29 10:01:00',
        ]);

        $this->assertSame(1, $invoice->initial_email_attempt_count);
        $this->assertNotNull($invoice->initial_email_last_attempt_at);
        $this->assertNotNull($invoice->initial_email_sent_at);
    }
}
