<?php

namespace App\Services;

use App\Models\FinancialEntry;
use App\Models\MoneyFlowLog;
use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Support\Carbon;

class MoneyFlowLogService
{
    public function payment(Payment $payment): MoneyFlowLog
    {
        $wallet = match ($payment->payment_method) {
            'cash' => 'cash', 'gcash' => 'gcash',
            'bank_bpi' => 'bpi', 'bank_landbank' => 'landbank',
            default => 'paymongo',
        };

        return MoneyFlowLog::firstOrCreate(
            ['idempotency_key' => 'payment:'.$payment->id],
            $this->base($payment, 'payment_received', (float) $payment->amount, [
                'payment_method' => $payment->payment_method,
                'effect_type' => 'cash_in',
                'destination_wallet' => $wallet,
                'occurred_at' => $payment->payment_date?->startOfDay() ?? now(),
                'metadata' => ['payment_number' => $payment->payment_number, 'invoice_id' => $payment->invoice_id],
            ])
        );
    }

    public function refund(PaymentRefund $refund): MoneyFlowLog
    {
        return MoneyFlowLog::firstOrCreate(
            ['idempotency_key' => 'refund:'.$refund->id],
            $this->base($refund, 'payment_refunded', (float) $refund->amount, [
                'payment_method' => $refund->payment_method,
                'effect_type' => 'expense',
                'source_wallet' => $refund->payment_method === 'cash' ? 'cash' : 'paymongo',
                'occurred_at' => $refund->refunded_at ?? now(),
                'metadata' => ['payment_id' => $refund->payment_id, 'reason' => $refund->reason],
            ])
        );
    }

    public function financialEntry(FinancialEntry $entry): MoneyFlowLog
    {
        return MoneyFlowLog::firstOrCreate(
            ['idempotency_key' => 'financial-entry:'.$entry->id],
            $this->base($entry, 'financial_entry_recorded', (float) $entry->amount, [
                'payment_method' => $entry->payment_method,
                'effect_type' => $entry->effect_type ?: ($entry->type === 'expense' ? 'expense' : 'cash_in'),
                'source_wallet' => $entry->source_wallet,
                'destination_wallet' => $entry->destination_wallet,
                'occurred_at' => $entry->entry_date?->startOfDay() ?? now(),
                'metadata' => ['category' => $entry->category, 'reference' => $entry->reference],
            ])
        );
    }

    private function base(object $source, string $event, float $amount, array $values): array
    {
        return array_merge([
            'tenant_id' => $source->tenant_id ?? null,
            'event_type' => $event,
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'amount' => $amount,
            'recorded_by' => auth()->id(),
        ], $values);
    }
}
