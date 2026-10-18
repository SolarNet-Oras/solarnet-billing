<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoicePaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicInvoicePaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_payment_link_survives_an_invoice_number_correction(): void
    {
        $customer = Customer::create([
            'account_number' => '2000000199',
            'full_name' => 'Payment Link Customer',
            'email' => 'payment-link@example.com',
            'address' => 'Test address',
            'contact_number' => '09170000199',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => 15,
            'monthly_fee' => 800,
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-202610-0199',
            'issue_date' => '2026-10-08',
            'due_date' => '2026-10-15',
            'billing_period_start' => '2026-09-15',
            'billing_period_end' => '2026-10-15',
            'total' => 800,
            'balance' => 800,
            'status' => 'sent',
        ]);

        $token = app(InvoicePaymentLinkService::class)->token($invoice);
        $invoice->update(['invoice_number' => 'SLR-202610-0199']);

        $this->getJson('/api/v1/customer-portal/pay/'.$token)
            ->assertOk()
            ->assertJsonPath('data.invoice.invoice_number', 'SLR-202610-0199')
            ->assertJsonPath('data.customer.account_number', '2000000199');
    }

    public function test_tampered_payment_link_remains_rejected(): void
    {
        $this->getJson('/api/v1/customer-portal/pay/not-a-valid-token')
            ->assertNotFound();
    }
}
