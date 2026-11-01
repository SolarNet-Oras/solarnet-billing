<?php

namespace App\Services;

use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Str;

class InvoiceDiscountService
{
    /** Record an approved invoice discount once in the Daily Operations ledger. */
    public function record(Invoice $invoice, float $amount, ?User $actor, string $source): ?FinancialEntry
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            return null;
        }

        return FinancialEntry::create([
            'type' => 'expense',
            'category' => 'Customer Discounts',
            'description' => 'Approved discount for invoice '.$invoice->invoice_number,
            'amount' => number_format($amount, 2, '.', ''),
            'entry_date' => now(config('app.timezone', 'Asia/Manila'))->toDateString(),
            'payment_method' => 'cash',
            'effect_type' => 'expense',
            'source_wallet' => 'cash',
            'destination_wallet' => null,
            'reference' => $invoice->invoice_number,
            'notes' => sprintf(
                'Invoice discount recorded automatically from %s for customer %s. This entry must be reused, not duplicated, during remittance liquidation.',
                $source,
                $invoice->customer_id
            ),
            'idempotency_key' => Str::uuid(),
            'recorded_by' => $actor?->id,
        ]);
    }
}
