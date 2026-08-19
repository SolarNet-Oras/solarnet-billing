<?php

namespace App\Services;

use App\Models\CustomerCredit;
use App\Models\FinancialEntry;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Remittance;
use Carbon\Carbon;

/**
 * Read-only operational finance summary.
 *
 * This service deliberately does not create a second accounting ledger. It
 * derives a concise, period-based view from the same payment, remittance,
 * invoice, credit, and daily-operation records used by the existing system.
 */
class FinancialMonitoringService
{
    private const WALLETS = ['cash', 'gcash', 'bpi', 'landbank', 'online'];

    /**
     * @return array<string, mixed>
     */
    public function summary(?string $month = null): array
    {
        $period = $this->resolvePeriod($month);
        $start = $period['start'];
        $end = $period['end'];

        $entries = FinancialEntry::query()
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'type', 'amount', 'payment_method', 'effect_type', 'source_wallet', 'destination_wallet']);

        // Collector cash becomes company cash only after cashier liquidation.
        // This is the same control used by Daily Operations and prevents the
        // monitor from overstating Cash before a collector turns it over.
        $regularCollections = Payment::query()
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($query) => $query->where('payment_method', '!=', 'cash')->orWhereNull('collector_id'))
            ->get(['id', 'amount', 'payment_method']);

        $liquidatedCollectorCash = Payment::query()
            ->where('payment_method', 'cash')
            ->whereNotNull('collector_id')
            ->whereHas('remittance', fn ($query) => $query
                ->whereNotNull('liquidated_at')
                ->whereBetween('liquidated_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]))
            ->get(['id', 'amount', 'payment_method']);

        $collections = $regularCollections->concat($liquidatedCollectorCash)->values();
        $wallets = self::calculateWallets($collections, $entries);

        $billed = (float) Invoice::query()
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->sum('total');

        $liveReceivables = Invoice::query()
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->where('balance', '>', 0);

        $outstanding = (float) (clone $liveReceivables)->sum('balance');
        $overdue = (float) (clone $liveReceivables)
            ->whereDate('due_date', '<', now(config('app.timezone', 'Asia/Manila'))->startOfDay())
            ->sum('balance');
        $openInvoices = (int) (clone $liveReceivables)->count();

        $pendingRemittances = Remittance::query()
            ->whereIn('status', ['submitted', 'discrepancy'])
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(declared_amount), 0) as amount')
            ->first();

        $availableAdvanceCredit = (float) CustomerCredit::query()
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount');

        $totalCollections = self::rounded(array_sum(array_column($wallets, 'collections')));
        $cashIn = self::rounded(array_sum(array_column($wallets, 'cash_in')));
        $expenses = self::rounded(array_sum(array_column($wallets, 'expenses')));
        $netMovement = self::rounded($totalCollections + $cashIn - $expenses);

        return [
            'period' => [
                'month' => $period['month'],
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'timezone' => config('app.timezone', 'Asia/Manila'),
            ],
            'flow' => [
                'billed' => self::rounded($billed),
                'collections' => $totalCollections,
                'cash_in' => $cashIn,
                'expenses' => $expenses,
                'net_operating_movement' => $netMovement,
            ],
            'wallets' => $wallets,
            'accounts_receivable' => [
                'open_invoice_count' => $openInvoices,
                'outstanding_balance' => self::rounded($outstanding),
                'overdue_balance' => self::rounded($overdue),
                'available_advance_credit' => self::rounded($availableAdvanceCredit),
            ],
            'remittances' => [
                'pending_count' => (int) ($pendingRemittances?->count ?? 0),
                'pending_declared_amount' => self::rounded((float) ($pendingRemittances?->amount ?? 0)),
            ],
            'data_sources' => [
                'Invoice issue-date totals for billed amount; invoices are not treated as collected cash.',
                'Payments by payment date, with collector cash counted on remittance liquidation date.',
                'Financial entries for approved cash-in, transfers, and expenses.',
                'Open invoice balances for live receivables and CustomerCredit for unspent advance credit.',
            ],
            'limitations' => [
                'Wallet figures are operational movement for the selected month, not a bank statement or a formal opening/closing general-ledger balance.',
                'Internal wallet transfers are excluded from net operating movement because they do not create or spend company funds.',
            ],
            'generated_at' => now(config('app.timezone', 'Asia/Manila'))->toIso8601String(),
        ];
    }

    /**
     * Pure deterministic wallet calculation, kept public for regression tests.
     *
     * @param iterable<array|object> $collections
     * @param iterable<array|object> $entries
     * @return array<string, array{collections: float, cash_in: float, transfers_in: float, transfers_out: float, expenses: float, balance: float}>
     */
    public static function calculateWallets(iterable $collections, iterable $entries): array
    {
        $wallets = collect(self::WALLETS)->mapWithKeys(fn (string $wallet) => [$wallet => [
            'collections' => 0.0,
            'cash_in' => 0.0,
            'transfers_in' => 0.0,
            'transfers_out' => 0.0,
            'expenses' => 0.0,
            'balance' => 0.0,
        ]])->all();

        foreach ($collections as $collection) {
            $wallet = self::walletFor(self::value($collection, 'payment_method'));
            if (isset($wallets[$wallet])) {
                $wallets[$wallet]['collections'] += (float) self::value($collection, 'amount');
            }
        }

        foreach ($entries as $entry) {
            $amount = (float) self::value($entry, 'amount');
            $effect = self::value($entry, 'effect_type') ?: (self::value($entry, 'type') === 'expense' ? 'expense' : 'cash_in');

            if ($effect === 'expense') {
                $wallet = self::value($entry, 'source_wallet') ?: self::walletFor(self::value($entry, 'payment_method'));
                if (isset($wallets[$wallet])) $wallets[$wallet]['expenses'] += $amount;
            }

            if ($effect === 'transfer') {
                $source = self::value($entry, 'source_wallet');
                $destination = self::value($entry, 'destination_wallet');
                if (isset($wallets[$source])) $wallets[$source]['transfers_out'] += $amount;
                if (isset($wallets[$destination])) $wallets[$destination]['transfers_in'] += $amount;
            }

            if ($effect === 'cash_in') {
                $wallet = self::value($entry, 'destination_wallet') ?: self::walletFor(self::value($entry, 'payment_method'));
                if (isset($wallets[$wallet])) $wallets[$wallet]['cash_in'] += $amount;
            }
        }

        foreach ($wallets as &$wallet) {
            $wallet['collections'] = self::rounded($wallet['collections']);
            $wallet['cash_in'] = self::rounded($wallet['cash_in']);
            $wallet['transfers_in'] = self::rounded($wallet['transfers_in']);
            $wallet['transfers_out'] = self::rounded($wallet['transfers_out']);
            $wallet['expenses'] = self::rounded($wallet['expenses']);
            $wallet['balance'] = self::rounded($wallet['collections'] + $wallet['cash_in'] + $wallet['transfers_in'] - $wallet['transfers_out'] - $wallet['expenses']);
        }

        return $wallets;
    }

    /** @return array{month: string, start: Carbon, end: Carbon} */
    private function resolvePeriod(?string $month): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $date = $month
            ? Carbon::createFromFormat('Y-m', $month, $timezone)
            : now($timezone);

        return [
            'month' => $date->format('Y-m'),
            'start' => $date->copy()->startOfMonth(),
            'end' => $date->copy()->endOfMonth(),
        ];
    }

    private static function walletFor(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'cash', 'add_to_cash' => 'cash',
            'gcash', 'ewallet', 'e_wallet', 'mobile_money', 'maya', 'paymaya', 'add_to_gcash' => 'gcash',
            'bank_bpi', 'deposit_to_bpi', 'bank', 'bank_transfer', 'transfer' => 'bpi',
            'bank_landbank', 'deposit_to_landbank' => 'landbank',
            'online', 'credit_card' => 'online',
            default => 'other',
        };
    }

    private static function value(array|object $item, string $key): mixed
    {
        return is_array($item) ? ($item[$key] ?? null) : ($item->{$key} ?? null);
    }

    private static function rounded(float $value): float
    {
        return round($value, 2);
    }
}
