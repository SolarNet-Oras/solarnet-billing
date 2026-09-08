<?php

namespace App\Services;

use App\Models\FinancialEntry;
use App\Models\CustomerCredit;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentRefund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentRefundService
{
    public function refund(Payment $payment, float $amount, string $reason, ?User $actor = null): PaymentRefund
    {
        return DB::transaction(function () use ($payment, $amount, $reason, $actor): PaymentRefund {
            $payment = Payment::query()->with('refunds')->lockForUpdate()->findOrFail($payment->id);
            if ($payment->payment_method !== 'cash' || ! $payment->remittance_id) {
                throw ValidationException::withMessages(['payment' => 'This command supports only a recorded cash payment with a remittance audit trail.']);
            }
            $refundCents = $this->cents($amount);
            $alreadyRefunded = $this->cents($payment->refunds->sum('amount'));
            $received = $this->cents($payment->amount);

            if ($refundCents <= 0 || $alreadyRefunded + $refundCents > $received) {
                throw ValidationException::withMessages(['amount' => 'Refund exceeds the remaining refundable payment amount.']);
            }

            $remaining = $refundCents;
            $invoiceIds = [];

            // Refund unused advance credit before touching invoice allocations.
            // This keeps the invoice payment intact when only retained change is returned.
            $credits = CustomerCredit::query()
                ->where('payment_id', $payment->id)
                ->where('remaining_amount', '>', 0)
                ->latest('created_at')->latest('id')->lockForUpdate()->get();

            foreach ($credits as $credit) {
                if ($remaining <= 0) break;
                $available = $this->cents($credit->remaining_amount);
                $reversed = min($remaining, $available);
                $newRemaining = $available - $reversed;
                $credit->update([
                    'remaining_amount' => $this->money($newRemaining),
                    'status' => $newRemaining === 0 ? 'refunded' : $credit->status,
                    'applied_at' => $newRemaining === 0 ? now() : $credit->applied_at,
                    'notes' => trim(($credit->notes ? $credit->notes.' ' : '').'Refunded '.$this->money($reversed).' from '.$payment->payment_number.'.'),
                ]);
                $remaining -= $reversed;
            }

            $allocations = PaymentAllocation::query()
                ->where('payment_id', $payment->id)
                ->latest('created_at')->latest('id')->lockForUpdate()->get();

            foreach ($allocations as $allocation) {
                if ($remaining <= 0) break;
                $allocated = $this->cents($allocation->amount);
                $reversed = min($remaining, $allocated);
                $invoiceIds[] = $allocation->invoice_id;
                if ($reversed === $allocated) {
                    $allocation->delete();
                } else {
                    $allocation->update(['amount' => $this->money($allocated - $reversed)]);
                }
                $remaining -= $reversed;
            }

            if ($remaining > 0) {
                throw ValidationException::withMessages(['amount' => 'This payment does not have enough unused credit or invoice allocation to reverse safely.']);
            }

            $entry = FinancialEntry::create([
                'type' => 'expense',
                'description' => 'Customer payment refund - ' . $payment->payment_number,
                'category' => 'Refund',
                'amount' => $this->money($refundCents),
                'entry_date' => now(config('app.timezone', 'Asia/Manila'))->toDateString(),
                'payment_method' => $payment->payment_method,
                'effect_type' => 'expense',
                'source_wallet' => $payment->payment_method === 'cash' ? 'cash' : $payment->payment_method,
                'reference' => 'REFUND-' . $payment->payment_number,
                'notes' => $reason,
                'recorded_by' => $actor?->id,
            ]);

            $refund = PaymentRefund::create([
                'payment_id' => $payment->id,
                'financial_entry_id' => $entry->id,
                'refunded_by' => $actor?->id,
                'amount' => $this->money($refundCents),
                'payment_method' => $payment->payment_method,
                'reason' => $reason,
                'refunded_at' => now(),
            ]);

            foreach (array_unique($invoiceIds) as $invoiceId) {
                app(InvoiceService::class)->reconcileInvoiceFromAllocations(
                    \App\Models\Invoice::query()->lockForUpdate()->findOrFail($invoiceId)
                );
            }

            return $refund->load(['payment.invoice', 'financialEntry']);
        });
    }

    private function cents(mixed $amount): int { return (int) round((float) $amount * 100); }
    private function money(int $cents): string { return number_format($cents / 100, 2, '.', ''); }
}
