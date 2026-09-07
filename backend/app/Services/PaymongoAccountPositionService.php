<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymongoAccountPositionService
{
    /** Read-only provider position. No payout, transfer, or wallet mutation is performed. */
    public function snapshot(): array
    {
        if (!config('services.paymongo.secret_key')) {
            return $this->unavailable('PayMongo is not configured.');
        }

        return Cache::remember('paymongo:account-position', now()->addMinute(), function (): array {
            try {
                $key = (string) config('services.paymongo.secret_key');
                $origin = preg_replace('#/v1/?$#', '', rtrim((string) config('services.paymongo.base_url'), '/'));
                $walletResponse = Http::withBasicAuth($key, '')->acceptJson()->timeout(12)
                    ->get($origin.'/v2/wallets', ['status' => 'activated', 'fields' => 'balance']);

                if (!$walletResponse->successful()) {
                    return $this->unavailable('PayMongo wallet balance is unavailable for this account or API key.');
                }

                $wallets = collect($walletResponse->json('data', []));
                $wallet = $wallets->first(fn ($row) => ($row['is_default'] ?? false) === true) ?? $wallets->first();
                if (!$wallet) return $this->unavailable('No active PayMongo wallet was returned.');

                $available = data_get($wallet, 'balance.available');
                $pending = data_get($wallet, 'balance.pending');
                $merchantId = data_get($wallet, 'merchant_id');
                $nextPayout = null;

                if ($merchantId) {
                    $scheduleResponse = Http::withBasicAuth($key, '')->acceptJson()->timeout(12)
                        ->get($origin.'/v1/merchants/'.$merchantId.'/schedules');
                    if ($scheduleResponse->successful()) {
                        $lineup = $scheduleResponse->json('data.attributes.lineup', []);
                        $nextPayout = is_array($lineup) ? ($lineup[0] ?? null) : null;
                    }
                }

                return [
                    'available' => is_numeric($available) ? ((int) $available) / 100 : null,
                    'pending' => is_numeric($pending) ? ((int) $pending) / 100 : null,
                    'total_balance' => is_numeric($available) || is_numeric($pending)
                        ? (((int) ($available ?? 0)) + ((int) ($pending ?? 0))) / 100
                        : null,
                    'next_payout_amount' => is_numeric(data_get($nextPayout, 'amount')) ? ((int) data_get($nextPayout, 'amount')) / 100 : null,
                    'next_payout_receive_at' => is_numeric(data_get($nextPayout, 'receive_at'))
                        ? date(DATE_ATOM, (int) data_get($nextPayout, 'receive_at'))
                        : null,
                    'currency' => data_get($nextPayout, 'currency') ?: data_get($wallet, 'account.currency') ?: 'PHP',
                    'livemode' => (bool) ($wallet['livemode'] ?? false),
                    'status' => 'available',
                    'message' => null,
                    'refreshed_at' => now()->toIso8601String(),
                ];
            } catch (\Throwable $exception) {
                Log::warning('PayMongo account position read failed', ['exception' => $exception::class]);
                return $this->unavailable('PayMongo did not return wallet or payout information.');
            }
        });
    }

    private function unavailable(string $message): array
    {
        return [
            'available' => null, 'pending' => null, 'total_balance' => null,
            'next_payout_amount' => null, 'next_payout_receive_at' => null,
            'currency' => 'PHP', 'livemode' => null, 'status' => 'unavailable',
            'message' => $message, 'refreshed_at' => now()->toIso8601String(),
        ];
    }
}
