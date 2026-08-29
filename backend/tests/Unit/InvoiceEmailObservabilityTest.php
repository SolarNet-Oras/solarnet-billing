<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class InvoiceEmailObservabilityTest extends TestCase
{
    public function test_missing_recipient_email_is_logged_with_a_structured_result(): void
    {
        Log::spy();

        $invoice = $this->invoiceFor(new Customer([
            'full_name' => 'No Email Customer',
            'email' => null,
        ]), 800);

        $result = app(InvoiceService::class)->sendInitialInvoiceEmail($invoice);

        $this->assertSame('skipped_no_email', $result);
        Log::shouldHaveReceived('info')->once()->with(
            'Initial invoice email skipped: no recipient email',
            Mockery::on(fn (array $context): bool =>
                $context['invoice_number'] === 'INV-TEST-OBSERVABILITY'
                && $context['customer_id'] === 'customer-test-id'
            ),
        );
    }

    public function test_zero_balance_skip_is_logged_with_the_balance(): void
    {
        Log::spy();

        $invoice = $this->invoiceFor(new Customer([
            'full_name' => 'Paid Customer',
            'email' => 'paid@example.test',
        ]), 0);

        $result = app(InvoiceService::class)->sendInitialInvoiceEmail($invoice);

        $this->assertSame('skipped_no_balance', $result);
        Log::shouldHaveReceived('info')->once()->with(
            'Initial invoice email skipped: zero balance',
            Mockery::on(fn (array $context): bool =>
                $context['invoice_number'] === 'INV-TEST-OBSERVABILITY'
                && $context['customer_id'] === 'customer-test-id'
                && $context['balance'] === 0.0
            ),
        );
    }

    private function invoiceFor(Customer $customer, float $balance): Invoice
    {
        $customer->forceFill(['id' => 'customer-test-id']);

        $invoice = new Invoice([
            'invoice_number' => 'INV-TEST-OBSERVABILITY',
            'customer_id' => 'customer-test-id',
            'balance' => $balance,
        ]);
        $invoice->forceFill(['id' => 'invoice-test-id']);
        $invoice->setRelation('customer', $customer);
        $invoice->setRelation('items', new Collection());
        $invoice->setRelation('payments', new Collection());

        return $invoice;
    }
}
