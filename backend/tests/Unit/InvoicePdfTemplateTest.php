<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Support\Collection;
use Tests\TestCase;

class InvoicePdfTemplateTest extends TestCase
{
    public function test_invoice_pdf_template_renders_company_website_and_tax_id(): void
    {
        $customer = new Customer([
            'full_name' => 'Invoice Test Customer',
            'account_number' => '1234567890',
            'address' => 'Oras, Eastern Samar',
            'email' => 'customer@example.test',
            'contact_number' => '09171234567',
        ]);
        $customer->setRelation('servicePlan', null);

        $invoice = new Invoice([
            'invoice_number' => 'INV-TEST-0001',
            'issue_date' => '2026-08-29',
            'due_date' => '2026-09-05',
            'billing_period_start' => '2026-08-05',
            'billing_period_end' => '2026-09-04',
            'subtotal' => 800,
            'tax' => 0,
            'discount' => 0,
            'total' => 800,
            'paid_amount' => 0,
            'balance' => 800,
            'status' => 'sent',
        ]);
        $invoice->setRelation('customer', $customer);
        $invoice->setRelation('items', new Collection([
            new InvoiceItem([
                'description' => 'Monthly internet service',
                'quantity' => 1,
                'unit_price' => 800,
                'total' => 800,
            ]),
        ]));
        $invoice->setRelation('payments', new Collection());

        $html = view('invoices.pdf', [
            'invoice' => $invoice,
            'company' => [
                'name' => 'SolarNet',
                'tagline' => 'High-Speed Internet & Network Solutions',
                'address' => 'Oras, Eastern Samar',
                'phone' => '09000000000',
                'email' => 'billing@example.test',
                'website' => 'https://solarnetportal.com',
                'tax_id' => 'TIN-123',
            ],
        ])->render();

        $this->assertStringContainsString('Website: https://solarnetportal.com', $html);
        $this->assertStringContainsString('Tax ID: TIN-123', $html);
    }
}
