<?php

namespace App\Services;

use App\Models\FinancialEntry;
use App\Models\PaymongoOutwardTransfer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymongoOutwardTransferService
{
    /** Persist a signed PayMongo transfer event and post only terminal success to Daily Operations. */
    public function receive(array $resource, string $eventId, string $eventType): void
    {
        $attributes = data_get($resource, 'attributes', $resource);
        $providerId = (string) (data_get($resource, 'id') ?: data_get($attributes, 'transfer_id'));
        if ($providerId === '' || !str_starts_with($providerId, 'tr_')) return;

        DB::transaction(function () use ($attributes, $providerId, $eventId, $eventType): void {
            $account = (string) data_get($attributes, 'receiver.bank_account_number', '');
            $status = str_contains($eventType, 'successful') ? 'succeeded' : (str_contains($eventType, 'failed') ? 'failed' : strtolower((string) data_get($attributes, 'status', 'pending')));
            $transfer = PaymongoOutwardTransfer::query()->lockForUpdate()->firstOrNew(['provider_transfer_id' => $providerId]);
            $transfer->fill([
                'webhook_event_id' => $eventId ?: $transfer->webhook_event_id,
                'reference_number' => data_get($attributes, 'reference_number'),
                'provider_reference_number' => data_get($attributes, 'provider_reference_number'),
                'recipient_name' => data_get($attributes, 'receiver.bank_account_name') ?: data_get($attributes, 'destination_account.name'),
                'recipient_institution' => data_get($attributes, 'receiver.bank_name') ?: data_get($attributes, 'destination_account.bank_name'),
                'recipient_account_last4' => $account !== '' ? substr($account, -4) : null,
                'rail' => data_get($attributes, 'provider'),
                'amount' => ((int) data_get($attributes, 'amount', 0)) / 100,
                'fee' => ((int) data_get($attributes, 'fee', 0)) / 100,
                'currency' => strtoupper((string) data_get($attributes, 'currency', 'PHP')),
                'status' => $status,
                'failure_reason' => data_get($attributes, 'provider_error'),
                'provider_created_at' => $this->providerTime(data_get($attributes, 'created_at')),
                'provider_updated_at' => $this->providerTime(data_get($attributes, 'updated_at')),
            ]);
            $transfer->save();

            if ($status !== 'succeeded' || $transfer->financial_entry_id || $transfer->currency !== 'PHP') return;
            $totalDebit = round($transfer->amount + $transfer->fee, 2);
            $entry = FinancialEntry::create([
                'type' => 'expense', 'description' => 'PayMongo Send Funds', 'category' => 'PayMongo outward transfer',
                'amount' => $totalDebit, 'entry_date' => optional($transfer->provider_updated_at)->toDateString() ?: now()->toDateString(),
                'payment_method' => 'paymongo', 'effect_type' => 'expense', 'source_wallet' => 'paymongo',
                'reference' => $transfer->reference_number ?: $providerId,
                'notes' => trim(implode(' | ', array_filter([
                    'Provider-confirmed outward transfer', $transfer->rail, $transfer->recipient_name,
                    $transfer->recipient_institution, $transfer->recipient_account_last4 ? 'Account ending '.$transfer->recipient_account_last4 : null,
                    'Transfer '.number_format($transfer->amount, 2), 'Fee '.number_format($transfer->fee, 2),
                ]))),
                'idempotency_key' => Str::uuid(), 'recorded_by' => null,
            ]);
            $transfer->update(['financial_entry_id' => $entry->id]);
        });
    }

    private function providerTime(mixed $value): ?Carbon
    {
        if (!$value) return null;
        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse($value);
    }
}
