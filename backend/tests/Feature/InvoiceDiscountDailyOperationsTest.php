<?php

namespace Tests\Feature;

use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoiceDiscountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceDiscountDailyOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_invoice_discount_is_recorded_in_daily_operations(): void
    {
        $actor = User::factory()->create();
        $invoice = new Invoice([
            'invoice_number' => 'SLR-202610-TEST',
            'customer_id' => '01a00000-0000-7000-8000-000000000001',
        ]);

        $entry = app(InvoiceDiscountService::class)->record(
            $invoice,
            200,
            $actor,
            'test approval'
        );

        $this->assertNotNull($entry);
        $this->assertSame('Customer Discounts', $entry->category);
        $this->assertSame('expense', $entry->effect_type);
        $this->assertSame('cash', $entry->source_wallet);
        $this->assertSame('SLR-202610-TEST', $entry->reference);
        $this->assertEquals(200.0, $entry->amount);
        $this->assertSame($actor->id, $entry->recorded_by);
        $this->assertDatabaseCount('financial_entries', 1);
    }

    public function test_zero_or_reduced_discount_does_not_create_an_expense(): void
    {
        $invoice = new Invoice([
            'invoice_number' => 'SLR-202610-TEST',
            'customer_id' => '01a00000-0000-7000-8000-000000000001',
        ]);

        $this->assertNull(app(InvoiceDiscountService::class)->record($invoice, 0, null, 'test'));
        $this->assertNull(app(InvoiceDiscountService::class)->record($invoice, -50, null, 'test'));
        $this->assertSame(0, FinancialEntry::count());
    }
}
