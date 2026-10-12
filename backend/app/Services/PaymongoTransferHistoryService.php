<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaymongoTransferHistoryService
{
    /** Read PayMongo wallet transaction history without mutating provider state. */
    public function outgoing(string $from, string $to): array
    {
        $key = (string) config('services.paymongo.secret_key');
        if ($key === '') throw new RuntimeException('PayMongo is not configured.');

        $timezone = 'Asia/Manila';
        $fromDate = Carbon::createFromFormat('Y-m-d', $from, $timezone)->startOfDay();
        $toDate = Carbon::createFromFormat('Y-m-d', $to, $timezone)->endOfDay();
        if ($fromDate->gt($toDate)) throw new RuntimeException('The history start date must not be after the end date.');

        $origin = preg_replace('#/v1/?$#', '', rtrim((string) config('services.paymongo.base_url'), '/'));
        $walletResponse = Http::withBasicAuth($key, '')->acceptJson()->timeout(20)
            ->get($origin.'/v2/wallets', ['status' => 'activated']);
        if (! $walletResponse->successful()) throw new RuntimeException('PayMongo wallet lookup failed with HTTP '.$walletResponse->status().'.');

        $wallets = collect($walletResponse->json('data', []));
        $wallet = $wallets->first(fn ($row) => ($row['is_default'] ?? false) === true) ?? $wallets->first();
        $walletId = (string) data_get($wallet, 'id', '');
        if ($walletId === '') throw new RuntimeException('PayMongo returned no active wallet identifier.');

        $rows = [];
        for ($page = 1; $page <= 100; $page++) {
            $response = Http::withBasicAuth($key, '')->acceptJson()->timeout(20)
                ->get($origin.'/v1/wallets/'.$walletId.'/transactions', [
                    'page' => $page,
                    'limit' => 100,
                ]);
            if (! $response->successful()) throw new RuntimeException('PayMongo transfer-history lookup failed with HTTP '.$response->status().'.');

            $batch = $response->json('data', []);
            if (! is_array($batch)) $batch = [];
            foreach ($batch as $resource) {
                if (! is_array($resource) || ! $this->isOutgoingSendFunds($resource)) continue;
                $at = $this->providerTime(data_get($resource, 'attributes.updated_at') ?: data_get($resource, 'attributes.created_at'));
                if ($at && $at->copy()->timezone($timezone)->betweenIncluded($fromDate, $toDate)) $rows[] = $resource;
            }

            if (count($batch) < 100) break;
        }

        return collect($rows)->unique(fn (array $row) => (string) data_get($row, 'id'))->values()->all();
    }

    public function status(array $resource): string
    {
        return strtolower((string) data_get($resource, 'attributes.status', 'pending'));
    }

    private function isOutgoingSendFunds(array $resource): bool
    {
        $type = strtolower((string) (data_get($resource, 'attributes.type') ?: data_get($resource, 'type')));
        return in_array($type, ['send_money', 'outward_transfer', 'transfer_outward'], true)
            || str_starts_with((string) data_get($resource, 'id', ''), 'tr_');
    }

    private function providerTime(mixed $value): ?Carbon
    {
        if (! $value) return null;
        return is_numeric($value) ? Carbon::createFromTimestamp((int) $value) : Carbon::parse($value);
    }
}
