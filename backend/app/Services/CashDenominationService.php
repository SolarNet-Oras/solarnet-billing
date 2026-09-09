<?php

namespace App\Services;

use App\Models\DailyCashCount;
use App\Models\FinancialEntry;
use App\Models\Remittance;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class CashDenominationService
{
    public const DENOMINATIONS = [
        ['denomination' => 1000, 'kind' => 'bill'], ['denomination' => 500, 'kind' => 'bill'],
        ['denomination' => 200, 'kind' => 'bill'], ['denomination' => 100, 'kind' => 'bill'],
        ['denomination' => 50, 'kind' => 'bill'], ['denomination' => 20, 'kind' => 'bill'],
        ['denomination' => 20, 'kind' => 'coin'], ['denomination' => 10, 'kind' => 'coin'],
        ['denomination' => 5, 'kind' => 'coin'], ['denomination' => 1, 'kind' => 'coin'],
    ];

    public function normalize(array $rows): array
    {
        $counts = collect($rows)->mapWithKeys(function (array $row): array {
            $denomination = (int) ($row['denomination'] ?? 0);
            $kind = ($row['kind'] ?? null) === 'coin' ? 'coin' : ($denomination >= 20 ? 'bill' : 'coin');
            return [$this->key($denomination, $kind) => max(0, (int) ($row['count'] ?? 0))];
        });
        return collect(self::DENOMINATIONS)->map(function (array $definition) use ($counts): array {
            $denomination = $definition['denomination'];
            $kind = $definition['kind'];
            $count = (int) ($counts[$this->key($denomination, $kind)] ?? 0);
            return ['denomination' => $denomination, 'count' => $count, 'amount' => $denomination * $count, 'kind' => $kind];
        })->all();
    }

    public function assertEqualsAmount(array $breakdown, mixed $amount): void
    {
        if ((int) collect($breakdown)->sum('amount') * 100 !== (int) round((float) $amount * 100)) {
            throw ValidationException::withMessages(['cash_breakdown' => 'Cash denomination total must equal the transaction amount.']);
        }
    }

    public function livePosition(?Carbon $through = null): array
    {
        $through ??= now();
        $baseline = DailyCashCount::query()->where('created_at', '<=', $through)->latest('created_at')->first();
        $pieces = collect(self::DENOMINATIONS)->mapWithKeys(fn (array $row) => [$this->key($row['denomination'], $row['kind']) => 0])->all();
        $from = null;
        if ($baseline) {
            foreach ($baseline->breakdown ?? [] as $row) {
                $kind = ($row['kind'] ?? null) === 'coin' ? 'coin' : ((int) $row['denomination'] >= 20 ? 'bill' : 'coin');
                $pieces[$this->key((int) $row['denomination'], $kind)] = (int) $row['count'];
            }
            $from = $baseline->created_at;
        }

        Remittance::query()->whereNotNull('liquidated_at')->where('liquidated_at', '<=', $through)
            ->when($from, fn ($query) => $query->where('liquidated_at', '>', $from))
            ->whereNotNull('cash_breakdown')->orderBy('liquidated_at')->each(function (Remittance $remittance) use (&$pieces): void {
                $this->apply($pieces, $remittance->cash_breakdown ?? [], 1);
                $this->apply($pieces, $remittance->cash_return_breakdown ?? [], -1);
            });

        FinancialEntry::query()->where('created_at', '<=', $through)->when($from, fn ($query) => $query->where('created_at', '>', $from))
            ->whereNotNull('cash_breakdown')->orderBy('created_at')->each(function (FinancialEntry $entry) use (&$pieces): void {
                if ($entry->destination_wallet === 'cash') $this->apply($pieces, $entry->cash_breakdown ?? [], 1);
                if ($entry->source_wallet === 'cash') $this->apply($pieces, $entry->cash_breakdown ?? [], -1);
            });

        $rows = collect(self::DENOMINATIONS)->map(function (array $row) use ($pieces): array {
            $key = $this->key($row['denomination'], $row['kind']);
            return ['denomination' => $row['denomination'], 'count' => $pieces[$key], 'amount' => $row['denomination'] * $pieces[$key], 'kind' => $row['kind']];
        })->all();
        return ['breakdown' => $rows, 'amount' => collect($rows)->sum('amount'), 'baseline_at' => $baseline?->created_at];
    }

    private function apply(array &$pieces, array $rows, int $direction): void
    {
        foreach ($rows as $row) {
            $denomination = (int) ($row['denomination'] ?? 0);
            $kind = ($row['kind'] ?? null) === 'coin' ? 'coin' : ($denomination >= 20 ? 'bill' : 'coin');
            $key = $this->key($denomination, $kind);
            if (array_key_exists($key, $pieces)) $pieces[$key] += $direction * (int) ($row['count'] ?? 0);
        }
    }

    private function key(int $denomination, string $kind): string
    {
        return $denomination . '_' . $kind;
    }
}
