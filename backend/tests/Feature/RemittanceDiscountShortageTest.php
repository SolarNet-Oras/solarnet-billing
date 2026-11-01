<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\RemittanceController;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Remittance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class RemittanceDiscountShortageTest extends TestCase
{
    use RefreshDatabase;

    public function test_cash_shortage_can_be_approved_as_an_invoice_discount_during_liquidation(): void
    {
        $collector = User::factory()->create(['name' => 'Collector']);
        $cashier = User::factory()->create(['name' => 'Cashier']);
        $customer = Customer::create([
            'account_number' => '8683017756',
            'full_name' => 'Discount Test Customer',
            'address' => 'Oras, Eastern Samar',
            'contact_number' => '09999999999',
            'installation_date' => '2026-10-01',
            'monthly_fee' => '999.00',
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'invoice_number' => 'SLR-TEST-DISCOUNT',
            'customer_id' => $customer->id,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-31',
            'billing_period_start' => '2026-10-01',
            'billing_period_end' => '2026-10-31',
            'subtotal' => '999.00',
            'tax' => '0.00',
            'discount' => '0.00',
            'total' => '999.00',
            'paid_amount' => '999.00',
            'balance' => '0.00',
            'status' => 'paid',
        ]);
        $remittance = Remittance::create([
            'collector_id' => $collector->id,
            'declared_amount' => '999.00',
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        Payment::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'collector_id' => $collector->id,
            'remittance_id' => $remittance->id,
            'payment_number' => 'PAY-TEST-DISCOUNT',
            'amount' => '999.00',
            'payment_method' => 'cash',
            'payment_date' => '2026-10-31',
        ]);

        $request = Request::create('/api/v1/remittances/'.$remittance->id.'/liquidate', 'POST', [
            'cash_breakdown' => $this->cashBreakdownFor899(),
            'shortage_reason' => 'customer_invoice_discount',
            'expense_receipt_reference' => $invoice->invoice_number,
        ]);
        $request->setUserResolver(fn () => $cashier);

        $response = app(RemittanceController::class)->liquidate($request, $remittance->id);

        $this->assertSame(200, $response->status());
        $this->assertSame(-100.0, $remittance->fresh()->liquidation_variance);
        $this->assertSame('customer_invoice_discount', $remittance->fresh()->shortage_reason);
        $entry = FinancialEntry::sole();
        $this->assertSame('Customer Discounts', $entry->category);
        $this->assertSame($invoice->invoice_number, $entry->reference);
        $this->assertEquals(100.0, $entry->amount);
        $this->assertSame($cashier->id, $entry->recorded_by);
        $this->assertSame(1, ActivityLog::where('subject_id', $invoice->id)->count());
    }

    private function cashBreakdownFor899(): array
    {
        return [
            ['denomination' => 1000, 'kind' => 'bill', 'count' => 0],
            ['denomination' => 500, 'kind' => 'bill', 'count' => 1],
            ['denomination' => 200, 'kind' => 'bill', 'count' => 1],
            ['denomination' => 100, 'kind' => 'bill', 'count' => 1],
            ['denomination' => 50, 'kind' => 'bill', 'count' => 1],
            ['denomination' => 20, 'kind' => 'bill', 'count' => 2],
            ['denomination' => 20, 'kind' => 'coin', 'count' => 0],
            ['denomination' => 10, 'kind' => 'coin', 'count' => 0],
            ['denomination' => 5, 'kind' => 'coin', 'count' => 1],
            ['denomination' => 1, 'kind' => 'coin', 'count' => 4],
        ];
    }
}
