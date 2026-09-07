<?php

namespace App\Services;

use App\Models\DailyCashCount;
use App\Models\FinancialEntry;
use App\Models\Remittance;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class CashDenominationService
{
    public const DENOMINATIONS = [1000, 500, 200, 100, 50, 20, 10, 5, 1];

    public function normalize(array $rows): array
    {
        $counts = collect($rows)->mapWithKeys(fn (array $row) => [(int) ($row['denomination'] ?? 0) => max(0, (int) ($row['count'] ?? 0))]);
        return collect(self::DENOMINATIONS)->map(fn (int $denomination) => [
            'denomination' => $denomination,
            'count' => (int) ($counts[$denomination] ?? 0),
            'amount' => $denomination * (int) ($counts[$denomination] ?? 0),
            'kind' => $denomination >= 20 ? 'bill' : 'coin',
        ])->all();
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
        $pieces = collect(self::DENOMINATIONS)->mapWithKeys(fn (int $value) => [$value => 0])->all();
        $from = null;
        if ($baseline) {
            foreach ($baseline->breakdown ?? [] as $row) $pieces[(int) $row['denomination']] = (int) $row['count'];
            $from = $baseline->created_at;
        }

        Remittance::query()->whereNotNull('liquidated_at')->where('liquidated_at', '<=', $through)
            ->when($from, fn ($query) => $query->where('liquidated_at', '>', $from))
            ->whereNotNull('cash_breakdown')->orderBy('liquidated_at')->each(function (Remittance $remittance) use (&$pieces): void {
                $this->apply($pieces, $remittance->cash_breakdown ?? [], 1);
            });

        FinancialEntry::query()->where('created_at', '<=', $through)->when($from, fn ($query) => $query->where('created_at', '>', $from))
            ->whereNotNull('cash_breakdown')->orderBy('created_at')->each(function (FinancialEntry $entry) use (&$pieces): void {
                if ($entry->destination_wallet === 'cash') $this->apply($pieces, $entry->cash_breakdown ?? [], 1);
                if ($entry->source_wallet === 'cash') $this->apply($pieces, $entry->cash_breakdown ?? [], -1);
            });

        $rows = collect(self::DENOMINATIONS)->map(fn (int $denomination) => [
            'denomination' => $denomination, 'count' => $pieces[$denomination],
            'amount' => $denomination * $pieces[$denomination], 'kind' => $denomination >= 20 ? 'bill' : 'coin',
        ])->all();
        return ['breakdown' => $rows, 'amount' => collect($rows)->sum('amount'), 'baseline_at' => $baseline?->created_at];
    }

    private function apply(array &$pieces, array $rows, int $direction): void
    {
        foreach ($rows as $row) {
            $denomination = (int) ($row['denomination'] ?? 0);
            if (array_key_exists($denomination, $pieces)) $pieces[$denomination] += $direction * (int) ($row['count'] ?? 0);
        }
    }
}
