<?php

namespace App\Services;

use App\Models\CustomerCredit;
use App\Models\Invoice;
use App\Models\MoneyFlowLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentTransferService
{
    public function transfer(Payment $payment, Invoice $source, Invoice $target, float $amount, float $credit, string $reason, string $key): PaymentTransfer
    {
        return DB::transaction(function () use ($payment, $source, $target, $amount, $credit, $reason, $key): PaymentTransfer {
            if ($existing = PaymentTransfer::where('idempotency_key', $key)->first()) return $existing;
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $source = Invoice::lockForUpdate()->findOrFail($source->id);
            $target = Invoice::lockForUpdate()->findOrFail($target->id);
            $a = (int) round($amount * 100); $c = (int) round($credit * 100);
            if ($a <= 0 || $c < 0 || $a + $c !== (int) round($payment->amount * 100) || $source->id === $target->id || $payment->payment_method !== 'cash' || $source->customer_id !== $payment->customer_id || $source->customer_id === $target->customer_id || $source->tenant_id !== $target->tenant_id) {
                throw ValidationException::withMessages(['transfer' => 'Invalid payment transfer parties or payment method.']);
            }
            $allocation = PaymentAllocation::where('payment_id', $payment->id)->where('invoice_id', $source->id)->lockForUpdate()->first();
            if (! $allocation || PaymentAllocation::where('payment_id', $payment->id)->where('invoice_id', $target->id)->exists() || (int) round($allocation->amount * 100) !== $a + $c || (float) $target->balance < $amount) {
                throw ValidationException::withMessages(['amount' => 'Transfer must match the source allocation and fit the target balance.']);
            }
            $allocation->update(['invoice_id' => $target->id, 'amount' => number_format($a / 100, 2, '.', '')]);
            if ($c > 0) CustomerCredit::create(['customer_id' => $source->customer_id, 'payment_id' => $payment->id, 'original_amount' => number_format($c / 100, 2, '.', ''), 'remaining_amount' => number_format($c / 100, 2, '.', ''), 'status' => 'unallocated', 'notes' => 'Retained from transfer '.$key]);
            app(InvoiceService::class)->reconcileInvoiceFromAllocations($source->fresh());
            app(InvoiceService::class)->reconcileInvoiceFromAllocations($target->fresh());
            $transfer = PaymentTransfer::create(['tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id, 'source_invoice_id' => $source->id, 'target_invoice_id' => $target->id, 'source_customer_id' => $source->customer_id, 'target_customer_id' => $target->customer_id, 'amount' => number_format($a / 100, 2, '.', ''), 'retained_credit' => number_format($c / 100, 2, '.', ''), 'reason' => $reason, 'idempotency_key' => $key, 'created_by' => auth()->id()]);
            MoneyFlowLog::firstOrCreate(['idempotency_key' => 'transfer:'.$transfer->id], ['tenant_id' => $payment->tenant_id, 'event_type' => 'payment_transferred', 'source_type' => PaymentTransfer::class, 'source_id' => $transfer->id, 'amount' => $a / 100, 'effect_type' => 'transfer', 'payment_method' => 'cash', 'occurred_at' => now(), 'recorded_by' => auth()->id(), 'metadata' => ['source_invoice_id' => $source->id, 'target_invoice_id' => $target->id, 'retained_credit' => $c / 100]]);
            return $transfer;
        });
    }
}
