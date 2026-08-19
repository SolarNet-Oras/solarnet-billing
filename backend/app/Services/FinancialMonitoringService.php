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
            ->get(['id', 'type', 'amount', 'entry_date', 'payment_method', 'effect_type', 'source_wallet', 'destination_wallet']);

        // Collector cash becomes company cash only after cashier liquidation.
        // This is the same control used by Daily Operations and prevents the
        // monitor from overstating Cash before a collector turns it over.
        $regularCollections = Payment::query()
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($query) => $query->where('payment_method', '!=', 'cash')->orWhereNull('collector_id'))
            ->get(['id', 'amount', 'payment_date', 'payment_method']);

        $liquidatedCollectorCash = Payment::query()
            ->with('remittance:id,liquidated_at')
            ->where('payment_method', 'cash')
            ->whereNotNull('collector_id')
            ->whereHas('remittance', fn ($query) => $query
                ->whereNotNull('liquidated_at')
                ->whereBetween('liquidated_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()]))
            ->get(['id', 'remittance_id', 'amount', 'payment_date', 'payment_method']);

        $collections = $regularCollections
            ->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'recognized_date' => $payment->payment_date?->toDateString(),
            ])
            ->concat($liquidatedCollectorCash->map(fn (Payment $payment) => [
                'id' => $payment->id,
                'amount' => (float) $payment->amount,
                'payment_method' => $payment->payment_method,
                'recognized_date' => $payment->remittance?->liquidated_at?->toDateString(),
            ]))
            ->filter(fn (array $payment) => !empty($payment['recognized_date']))
            ->values();
        $wallets = self::calculateWallets($collections, $entries);

        $periodInvoices = Invoice::query()
            ->with('customer:id,full_name,account_number')
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->get(['id', 'customer_id', 'invoice_number', 'issue_date', 'due_date', 'billing_period_start', 'billing_period_end', 'total', 'balance', 'status']);
        $billed = (float) $periodInvoices->sum('total');

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
        $dailyMetrics = $this->dailyMetrics($period, $periodInvoices, $collections, $entries);
        $periodPayments = Payment::query()
            ->with(['customer:id,full_name,account_number', 'invoice:id,invoice_number'])
            ->whereBetween('payment_date', [$start->toDateString(), $end->toDateString()])
            ->get(['id', 'invoice_id', 'customer_id', 'payment_number', 'amount', 'payment_method', 'payment_date', 'transaction_id', 'reference']);
        $anomalies = $this->anomalySummary($periodInvoices, $periodPayments, $outstanding, $overdue, $pendingRemittances);
        $collectionRate = $billed > 0 ? self::rounded(($totalCollections / $billed) * 100) : null;
        $expenseRatio = $totalCollections > 0 ? self::rounded(($expenses / $totalCollections) * 100) : null;
        $study = $this->study($billed, $totalCollections, $expenses, $netMovement, $outstanding, $overdue, $pendingRemittances, $collectionRate);

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
                'collection_rate_percent' => $collectionRate,
                'expense_ratio_percent' => $expenseRatio,
            ],
            'wallets' => $wallets,
            'daily_metrics' => $dailyMetrics,
            'allocation_plan' => self::allocationPlan($totalCollections),
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
            'study' => $study,
            'anomalies' => $anomalies,
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

    /**
     * Planning-only distribution of actual monthly collections. This does not
     * reserve, transfer, approve, or create a credit facility.
     *
     * @return array{collection_base: float, planning_base: float, retained_operations: float, allocations: array<int, array{key: string, label: string, percent_of_planning_base: int, percent_of_collections: int, amount: float}>, note: string}
     */
    public static function allocationPlan(float $collections): array
    {
        $collections = max(0, self::rounded($collections));
        $planningBase = self::rounded($collections * 0.80);

        return [
            'collection_base' => $collections,
            'planning_base' => $planningBase,
            'retained_operations' => self::rounded($collections - $planningBase),
            'allocations' => [
                ['key' => 'business_line_of_credit', 'label' => 'Business Line of Credit limit', 'percent_of_planning_base' => 40, 'percent_of_collections' => 32, 'amount' => self::rounded($planningBase * 0.40)],
                ['key' => 'payroll_funding', 'label' => 'Payroll funding', 'percent_of_planning_base' => 30, 'percent_of_collections' => 24, 'amount' => self::rounded($planningBase * 0.30)],
                ['key' => 'emergency_fund', 'label' => 'Emergency fund', 'percent_of_planning_base' => 10, 'percent_of_collections' => 8, 'amount' => self::rounded($planningBase * 0.10)],
                ['key' => 'partner_dividends', 'label' => 'Dividend partners', 'percent_of_planning_base' => 20, 'percent_of_collections' => 16, 'amount' => self::rounded($planningBase * 0.20)],
            ],
            'note' => 'Planning targets from 80% of recognized monthly collections. The remaining 20% stays outside this plan for operations. No funds are moved or approved by this view.',
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, Invoice> $invoices
     * @param \Illuminate\Support\Collection<int, array{id: string, amount: float, payment_method: ?string, recognized_date: string}> $collections
     * @param \Illuminate\Support\Collection<int, FinancialEntry> $entries
     * @return array<int, array{date: string, billed: float, collections: float, cash_in: float, expenses: float, net_operating_movement: float}>
     */
    private function dailyMetrics(array $period, $invoices, $collections, $entries): array
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $lastDate = $period['end']->copy()->min(now($timezone)->endOfDay());
        if ($lastDate->lt($period['start'])) return [];

        $metrics = [];
        for ($date = $period['start']->copy(); $date->lte($lastDate); $date->addDay()) {
            $key = $date->toDateString();
            $metrics[$key] = ['date' => $key, 'billed' => 0.0, 'collections' => 0.0, 'cash_in' => 0.0, 'expenses' => 0.0, 'net_operating_movement' => 0.0];
        }

        foreach ($invoices as $invoice) {
            $key = $invoice->issue_date?->toDateString();
            if ($key && isset($metrics[$key])) $metrics[$key]['billed'] += (float) $invoice->total;
        }
        foreach ($collections as $collection) {
            $key = $collection['recognized_date'];
            if (isset($metrics[$key])) $metrics[$key]['collections'] += (float) $collection['amount'];
        }
        foreach ($entries as $entry) {
            $key = $entry->entry_date?->toDateString();
            if (!$key || !isset($metrics[$key])) continue;
            $amount = (float) $entry->amount;
            $effect = $entry->effect_type ?: ($entry->type === 'expense' ? 'expense' : 'cash_in');
            if ($effect === 'cash_in') $metrics[$key]['cash_in'] += $amount;
            if ($effect === 'expense') $metrics[$key]['expenses'] += $amount;
        }
        foreach ($metrics as &$metric) {
            $metric['billed'] = self::rounded($metric['billed']);
            $metric['collections'] = self::rounded($metric['collections']);
            $metric['cash_in'] = self::rounded($metric['cash_in']);
            $metric['expenses'] = self::rounded($metric['expenses']);
            $metric['net_operating_movement'] = self::rounded($metric['collections'] + $metric['cash_in'] - $metric['expenses']);
        }

        return array_values($metrics);
    }

    /** @return array{summary: array{review_count: int, duplicate_payment_count: int, duplicate_invoice_count: int}, items: array<int, array<string, mixed>>} */
    private function anomalySummary($invoices, $payments, float $outstanding, float $overdue, ?Remittance $pendingRemittances): array
    {
        $items = [];

        foreach ($payments->filter(fn (Payment $payment) => trim((string) ($payment->transaction_id ?: $payment->reference)) !== '')
            ->groupBy(fn (Payment $payment) => implode('|', [$payment->customer_id, strtolower((string) $payment->payment_method), trim((string) ($payment->transaction_id ?: $payment->reference))]))
            ->filter(fn ($group) => $group->count() > 1) as $group) {
            $first = $group->first();
            $items[] = [
                'type' => 'duplicate_payment_candidate',
                'severity' => 'review',
                'message' => 'Multiple payments use the same customer, payment channel, and transaction/reference value. Verify that this reference was not recorded twice.',
                'customer' => ['account_number' => $first->customer?->account_number, 'full_name' => $first->customer?->full_name],
                'reference' => $first->transaction_id ?: $first->reference,
                'payment_count' => $group->count(),
                'amount_total' => self::rounded((float) $group->sum('amount')),
                'payment_numbers' => $group->pluck('payment_number')->values()->all(),
            ];
        }

        foreach ($invoices->filter(fn (Invoice $invoice) => $invoice->billing_period_start && $invoice->billing_period_end)
            ->groupBy(fn (Invoice $invoice) => implode('|', [$invoice->customer_id, $invoice->billing_period_start?->toDateString(), $invoice->billing_period_end?->toDateString(), $invoice->total]))
            ->filter(fn ($group) => $group->count() > 1) as $group) {
            $first = $group->first();
            $items[] = [
                'type' => 'duplicate_invoice_candidate',
                'severity' => 'review',
                'message' => 'Multiple active invoices have the same customer, billed period, and total. Verify whether a duplicate manual invoice was created.',
                'customer' => ['account_number' => $first->customer?->account_number, 'full_name' => $first->customer?->full_name],
                'invoice_count' => $group->count(),
                'amount_total' => self::rounded((float) $group->sum('total')),
                'invoice_numbers' => $group->pluck('invoice_number')->values()->all(),
            ];
        }

        if (($pendingRemittances?->count ?? 0) > 0) {
            $items[] = [
                'type' => 'pending_collector_remittance',
                'severity' => 'review',
                'message' => 'Collector remittance is still submitted or marked discrepancy. It is not included as company cash until liquidation/receipt is completed.',
                'remittance_count' => (int) $pendingRemittances->count,
                'amount_total' => self::rounded((float) $pendingRemittances->amount),
            ];
        }
        if ($overdue > 0) {
            $items[] = [
                'type' => 'overdue_receivables',
                'severity' => 'monitor',
                'message' => 'Open receivables include amounts past their invoice due date. Review collection follow-up and suspension policy separately.',
                'amount_total' => self::rounded($overdue),
                'outstanding_total' => self::rounded($outstanding),
            ];
        }

        $items = array_slice($items, 0, 25);
        return [
            'summary' => [
                'review_count' => count($items),
                'duplicate_payment_count' => count(array_filter($items, fn (array $item) => $item['type'] === 'duplicate_payment_candidate')),
                'duplicate_invoice_count' => count(array_filter($items, fn (array $item) => $item['type'] === 'duplicate_invoice_candidate')),
            ],
            'items' => $items,
        ];
    }

    /** @return array{headline: string, findings: array<int, string>, action_required: string} */
    private function study(float $billed, float $collections, float $expenses, float $netMovement, float $outstanding, float $overdue, ?Remittance $pendingRemittances, ?float $collectionRate): array
    {
        $findings = [];
        if ($billed > 0) $findings[] = 'The operational collected-to-billed ratio is ' . number_format((float) ($collectionRate ?? 0), 2) . '% for the selected month. This is a monitoring ratio, not a formal invoice-level allocation rate.';
        if ($netMovement < 0) $findings[] = 'Approved expenses are greater than recognized collections plus cash-in for this month, producing a negative net operating movement.';
        elseif ($netMovement > 0) $findings[] = 'Recognized collections and cash-in exceed approved expenses for this month by ₱' . number_format($netMovement, 2) . '.';
        if ($outstanding > 0) $findings[] = 'Current open receivables total ₱' . number_format($outstanding, 2) . '; ₱' . number_format($overdue, 2) . ' is past due.';
        if (($pendingRemittances?->count ?? 0) > 0) $findings[] = 'Pending collector remittances require cashier review before cash is treated as company cash.';
        if ($findings === []) $findings[] = 'No billing, collection, expense, or remittance activity was recorded for the selected month.';

        return [
            'headline' => $netMovement < 0 ? 'Expense pressure needs review.' : ($outstanding > 0 ? 'Collections are positive, with receivables still requiring follow-up.' : 'No material receivable exception is visible in the current summary.'),
            'findings' => $findings,
            'action_required' => ($pendingRemittances?->count ?? 0) > 0 || $overdue > 0 ? 'Review the listed remittances and overdue accounts. This monitor will not make a financial correction.' : 'No finance correction is proposed. Use the AI interpreter only to explain the verified data.',
        ];
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
