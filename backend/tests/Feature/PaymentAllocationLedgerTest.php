<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAllocationLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_payment_settles_oldest_invoices_and_preserves_the_receipt_amount(): void
    {
        $customer = $this->customer();
        $july = $this->invoice($customer, '2026-07-01', '800.00', '300.00', '500.00', 'partial');
        $august = $this->invoice($customer, '2026-08-01', '800.00', '800.00');
        $september = $this->invoice($customer, '2026-09-01', '800.00', '800.00');

        // Represents the historical July receipt present before this migration.
        $oldPayment = \App\Models\Payment::create([
            'invoice_id' => $july->id,
            'customer_id' => $customer->id,
            'payment_number' => 'PAY-202607-0001',
            'amount' => '500.00',
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-07-15',
        ]);
        PaymentAllocation::create(['payment_id' => $oldPayment->id, 'invoice_id' => $july->id, 'amount' => '500.00']);

        $payment = app(InvoiceService::class)->recordPayment($september, [
            'amount' => '1900.00',
            'payment_method' => 'bank_transfer',
            'payment_date' => '2026-09-01',
            'transaction_id' => 'acceptance-payment-1900',
        ]);

        $this->assertSame('1900.00', $payment->amount);
        $this->assertSame('300.00', $payment->allocations()->where('invoice_id', $july->id)->value('amount'));
        $this->assertSame('800.00', $payment->allocations()->where('invoice_id', $august->id)->value('amount'));
        $this->assertSame('800.00', $payment->allocations()->where('invoice_id', $september->id)->value('amount'));
        foreach ([$july, $august, $september] as $invoice) {
            $this->assertSame('0.00', $invoice->fresh()->balance);
            $this->assertSame('paid', $invoice->fresh()->status);
        }
        $this->assertEquals(0.0, (float) Invoice::where('customer_id', $customer->id)->sum('balance'));
    }

    public function test_partial_payment_closes_oldest_and_partially_pays_next(): void
    {
        $customer = $this->customer();
        $july = $this->invoice($customer, '2026-07-01', '300.00', '300.00');
        $august = $this->invoice($customer, '2026-08-01', '800.00', '800.00');
        $september = $this->invoice($customer, '2026-09-01', '800.00', '800.00');

        app(InvoiceService::class)->recordPayment($september, [
            'amount' => '1000.00', 'payment_method' => 'bank_transfer', 'transaction_id' => 'partial-1000',
        ]);

        $this->assertSame('0.00', $july->fresh()->balance);
        $this->assertSame('100.00', $august->fresh()->balance);
        $this->assertSame('partial', $august->fresh()->status);
        $this->assertSame('800.00', $september->fresh()->balance);
    }

    public function test_overpayment_becomes_credit_and_cancelled_invoice_is_excluded(): void
    {
        $customer = $this->customer();
        $cancelled = $this->invoice($customer, '2026-07-01', '800.00', '800.00', '0.00', 'cancelled');
        $current = $this->invoice($customer, '2026-08-01', '800.00', '800.00');

        $payment = app(InvoiceService::class)->recordPayment($current, [
            'amount' => '1000.00', 'payment_method' => 'bank_transfer', 'transaction_id' => 'overpay-1000',
        ]);

        $this->assertSame('800.00', $cancelled->fresh()->balance);
        $this->assertSame('0.00', $current->fresh()->balance);
        $this->assertSame('200.00', $payment->fresh()->customer->credits()->sum('remaining_amount'));
    }

    private function customer(): Customer
    {
        return Customer::create([
            'account_number' => (string) random_int(1000000000, 9999999999),
            'full_name' => 'Allocation Test',
            'address' => 'Oras, Eastern Samar',
            'contact_number' => '09999999999',
            'installation_date' => '2026-07-01',
            'monthly_fee' => '800.00',
            'status' => 'active',
        ]);
    }

    private function invoice(Customer $customer, string $period, string $total, string $balance, string $paid = '0.00', string $status = 'sent'): Invoice
    {
        return Invoice::create([
            'invoice_number' => 'TEST-'.$period.'-'.substr((string) random_int(10000, 99999), 0, 5),
            'customer_id' => $customer->id,
            'issue_date' => $period,
            'due_date' => $period,
            'billing_period_start' => $period,
            'billing_period_end' => $period,
            'subtotal' => $total,
            'tax' => '0.00',
            'discount' => '0.00',
            'total' => $total,
            'paid_amount' => $paid,
            'balance' => $balance,
            'status' => $status,
        ]);
    }
}
