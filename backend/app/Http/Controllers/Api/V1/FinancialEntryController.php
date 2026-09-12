<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinancialEntry;
use App\Models\DailyCashCount;
use App\Models\Payment;
use App\Models\TransactionDefinition;
use App\Services\CashDenominationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class FinancialEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['date' => 'nullable|date', 'month' => 'nullable|date_format:Y-m']);
        $month = $request->string('month')->toString();
        if ($month !== '') {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth()->toDateString();
            $end = Carbon::createFromFormat('Y-m', $month)->endOfMonth()->toDateString();
            $entries = FinancialEntry::with('recorder:id,name')->whereBetween('entry_date', [$start, $end])->latest()->get();
            $period = ['mode' => 'month', 'value' => $month, 'start' => $start, 'end' => $end];
        } else {
            $date = $request->date('date')?->toDateString() ?? now()->toDateString();
            $start = $date;
            $end = $date;
            $entries = FinancialEntry::with('recorder:id,name')->whereDate('entry_date', $date)->latest()->get();
            $period = ['mode' => 'day', 'value' => $date, 'start' => $date, 'end' => $date];
        }
        $paymentRelations = ['customer:id,full_name,account_number', 'collector:id,name', 'receiver:id,name', 'remittance.liquidator:id,name'];
        // Cash collected by a field collector is not company cash until an
        // office user has counted and liquidated that remittance. It appears
        // on the liquidation date, rather than the original collection date.
        $regularCollections = Payment::with($paymentRelations)
            ->whereBetween('payment_date', [$start, $end])
            ->where(fn ($query) => $query->where('payment_method', '!=', 'cash')->orWhereNull('collector_id'))
            ->get();
        $liquidatedCollectorCash = Payment::with($paymentRelations)
            ->where('payment_method', 'cash')
            ->whereNotNull('collector_id')
            ->whereHas('remittance', fn ($query) => $query->whereNotNull('liquidated_at')->whereBetween('liquidated_at', [Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay()]))
            ->get();
        $collections = $regularCollections->concat($liquidatedCollectorCash)
            ->sortByDesc(fn (Payment $payment) => $payment->remittance?->liquidated_at ?? $payment->payment_date)
            ->values();
        // Wallet cards represent total company funds, independent of the
        // selected detail period. Count all valid movements through today.
        $balanceDate = now()->toDateString();
        $balanceCutoff = now()->endOfDay();
        $balanceCollections = Payment::query()->with('paymongoCheckout:id,payment_id,provider_fee,settlement_status')
            ->whereDate('payment_date', '<=', $balanceDate)
            ->where(fn ($query) => $query->where('payment_method', '!=', 'cash')->orWhereNull('collector_id'))
            ->get()
            ->concat(Payment::query()->with('paymongoCheckout:id,payment_id,provider_fee,settlement_status')
                ->where('payment_method', 'cash')
                ->whereNotNull('collector_id')
                ->whereHas('remittance', fn ($query) => $query->whereNotNull('liquidated_at')->where('liquidated_at', '<=', $balanceCutoff))
                ->get());
        $balanceEntries = FinancialEntry::query()->whereDate('entry_date', '<=', $balanceDate)->get();
        $wallets = collect(['cash', 'gcash', 'paymongo', 'bpi', 'landbank'])->mapWithKeys(fn (string $wallet) => [$wallet => ['collections' => 0.0, 'processing_fees' => 0.0, 'cash_in' => 0.0, 'transfers_in' => 0.0, 'transfers_out' => 0.0, 'expenses' => 0.0, 'balance' => 0.0]])->all();

        foreach ($balanceCollections as $collection) {
            $wallet = $collection->paymongoCheckout ? 'paymongo' : $this->walletFor($collection->payment_method);
            if (isset($wallets[$wallet])) {
                $wallets[$wallet]['collections'] += (float) $collection->amount;
                if ($collection->paymongoCheckout?->settlement_status === 'provider_confirmed') {
                    $wallets[$wallet]['processing_fees'] += (float) $collection->paymongoCheckout->provider_fee;
                }
            }
        }
        foreach ($balanceEntries as $entry) {
            $amount = (float) $entry->amount;
            $effect = $entry->effect_type ?: ($entry->type === 'expense' ? 'expense' : 'cash_in');
            if ($effect === 'expense') {
                $wallet = $entry->source_wallet ?: $this->walletFor($entry->payment_method);
                if (isset($wallets[$wallet])) $wallets[$wallet]['expenses'] += $amount;
            }
            if ($effect === 'transfer') {
                if (isset($wallets[$entry->source_wallet])) $wallets[$entry->source_wallet]['transfers_out'] += $amount;
                if (isset($wallets[$entry->destination_wallet])) $wallets[$entry->destination_wallet]['transfers_in'] += $amount;
            }
            if ($effect === 'cash_in') {
                $wallet = $entry->destination_wallet ?: $this->walletFor($entry->payment_method);
                if (isset($wallets[$wallet])) $wallets[$wallet]['cash_in'] += $amount;
            }
        }
        foreach ($wallets as &$wallet) $wallet['balance'] = $wallet['collections'] + $wallet['cash_in'] + $wallet['transfers_in'] - $wallet['transfers_out'] - $wallet['expenses'] - $wallet['processing_fees'];

        $cashCount = DailyCashCount::with('counter:id,name')
            ->whereDate('count_date', $end)
            ->latest('created_at')
            ->first();

        return response()->json(['data' => [
            'period' => $period,
            'wallet_balance_as_of' => $balanceDate,
            'collections' => $collections,
            'cash_in' => $entries->filter(fn (FinancialEntry $entry) => ($entry->effect_type ?: ($entry->type === 'expense' ? 'expense' : 'cash_in')) === 'cash_in')->values(),
            'transfers' => $entries->where('effect_type', 'transfer')->values(),
            'expenses' => $entries->filter(fn (FinancialEntry $entry) => ($entry->effect_type ?: ($entry->type === 'expense' ? 'expense' : 'cash_in')) === 'expense')->values(),
            'wallets' => $wallets,
            'cash_count' => $cashCount,
            'cash_denomination_position' => app(CashDenominationService::class)->livePosition(),
        ]]);
    }

    public function storeCashCount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'count_date' => ['required', 'date'],
            'breakdown' => ['required', 'array', 'size:10'],
            'breakdown.*.denomination' => ['required', 'integer', 'in:1000,500,200,100,50,20,10,5,1'],
            'breakdown.*.kind' => ['required', 'in:bill,coin'],
            'breakdown.*.count' => ['required', 'integer', 'min:0', 'max:100000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $breakdown = app(CashDenominationService::class)->normalize($data['breakdown']);
        $counted = (float) collect($breakdown)->sum('amount');

        // Reuse the authoritative cumulative wallet calculation returned by
        // this controller. A cash count reconciles the ledger; it never adds
        // income or silently changes the cash position.
        $ledgerRequest = Request::create('', 'GET', ['date' => $data['count_date']]);
        $ledgerRequest->setUserResolver(fn () => $request->user());
        $ledger = $this->index($ledgerRequest)->getData(true)['data'];
        $expected = round((float) ($ledger['wallets']['cash']['balance'] ?? 0), 2);

        $cashCount = DailyCashCount::create([
            'count_date' => $data['count_date'],
            'breakdown' => $breakdown,
            'counted_amount' => number_format($counted, 2, '.', ''),
            'expected_cash_balance' => number_format($expected, 2, '.', ''),
            'variance' => number_format($counted - $expected, 2, '.', ''),
            'counted_by' => $request->user()?->id,
            'notes' => isset($data['notes']) ? trim($data['notes']) : null,
        ]);

        return response()->json([
            'message' => 'Physical cash count saved for reconciliation. No wallet transaction was created.',
            'data' => $cashCount->load('counter:id,name'),
        ], 201);
    }

    public function definitions(Request $request): JsonResponse
    {
        $query = TransactionDefinition::query();
        if (!$request->boolean('include_inactive')) $query->where('active', true);
        if ($request->filled('type')) $query->where('type', $request->string('type'));
        if ($request->filled('description')) $query->where('description', $request->string('description'));
        return response()->json(['data' => $query->orderBy('sort_order')->get(['id', 'type', 'description', 'payment_method', 'effect_type', 'source_wallet', 'destination_wallet', 'active'])]);
    }

    public function createDefinition(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|max:100',
            'description' => 'required|string|max:255',
            'effect_type' => 'nullable|in:expense,cash_in,transfer',
            'payment_method' => 'required|in:cash,gcash,bank_bpi,bank_landbank,add_to_cash,add_to_gcash,deposit_to_bpi,deposit_to_landbank',
            'source_wallet' => 'nullable|in:cash,gcash,bpi,landbank',
        ]);
        $data['type'] = trim($data['type']);
        $data['description'] = trim($data['description']);
        $data['effect_type'] ??= $data['type'] === 'Cash In'
            ? (empty($data['source_wallet']) ? 'cash_in' : 'transfer')
            : 'expense';

        if (in_array($data['effect_type'], ['cash_in', 'transfer'], true)) {
            $destination = $this->walletFor($data['payment_method']);
            if ($destination === 'other' || in_array($data['payment_method'], ['cash', 'gcash', 'bank_bpi', 'bank_landbank'], true)) {
                return response()->json(['message' => 'New funds and transfers must choose Add to Cash/GCash or Deposit to BPI/Landbank.'], 422);
            }
            if ($data['effect_type'] === 'transfer' && empty($data['source_wallet'])) {
                return response()->json(['message' => 'Internal transfers require a source wallet.'], 422);
            }
            if ($data['effect_type'] === 'cash_in') $data['source_wallet'] = null;
            $effect = $data['effect_type'];
        } else {
            if (!in_array($data['payment_method'], ['cash', 'gcash', 'bank_bpi', 'bank_landbank'], true)) {
                return response()->json(['message' => 'Choose which wallet paid the expense: Cash, GCash, BPI, or Landbank.'], 422);
            }
            $data['source_wallet'] = $this->walletFor($data['payment_method']);
            $destination = null;
            $effect = 'expense';
        }

        $definition = TransactionDefinition::firstOrNew([
            'type' => $data['type'], 'description' => $data['description'], 'payment_method' => $data['payment_method'],
        ]);
        $definition->fill([
            'effect_type' => $effect,
            'source_wallet' => $data['source_wallet'] ?? null,
            'destination_wallet' => $destination,
            'active' => true,
            'sort_order' => $definition->exists ? $definition->sort_order : ((int) TransactionDefinition::max('sort_order') + 1),
        ])->save();
        return response()->json(['data' => $definition], $definition->wasRecentlyCreated ? 201 : 200);
    }

    public function deactivateDefinition(TransactionDefinition $transactionDefinition): JsonResponse
    {
        $transactionDefinition->update(['active' => false]);
        return response()->json(['data' => $transactionDefinition]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'transaction_definition_id' => 'required|uuid',
            'amount' => 'required|numeric|min:0.01',
            'entry_date' => 'required|date',
            'reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            'idempotency_key' => 'required|uuid',
            'cash_breakdown' => ['nullable', 'array', 'size:10'],
            'cash_breakdown.*.denomination' => ['required_with:cash_breakdown', 'integer', 'in:1000,500,200,100,50,20,10,5,1'],
            'cash_breakdown.*.kind' => ['required_with:cash_breakdown', 'in:bill,coin'],
            'cash_breakdown.*.count' => ['required_with:cash_breakdown', 'integer', 'min:0', 'max:100000'],
        ]);
        $definition = TransactionDefinition::query()->whereKey($data['transaction_definition_id'])->where('active', true)->first();
        if (!$definition) return response()->json(['message' => 'The selected transaction type, description, and payment method is not valid.'], 422);
        if ($definition->effect_type === 'cash_in' && $definition->source_wallet === null && in_array($definition->destination_wallet, ['cash', 'gcash', 'bpi', 'landbank'], true)) {
            abort_unless($request->user()?->hasRole('super_admin'), 403, 'Only a Super Administrator can add new funds to Cash, GCash, BPI, or Landbank.');
        }
        $touchesCash = $definition->source_wallet === 'cash' || $definition->destination_wallet === 'cash';
        if ($touchesCash) {
            if (! isset($data['cash_breakdown'])) return response()->json(['message' => 'Cash denominations are required for a cash disbursement, transfer, or addition.'], 422);
            $data['cash_breakdown'] = app(CashDenominationService::class)->normalize($data['cash_breakdown']);
            app(CashDenominationService::class)->assertEqualsAmount($data['cash_breakdown'], $data['amount']);
        } else {
            $data['cash_breakdown'] = null;
        }

        $entry = DB::transaction(function () use ($data, $definition, $request) {
            $existing = FinancialEntry::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) return $existing;
            return FinancialEntry::create([
                ...$data,
                'type' => $definition->effect_type === 'expense' ? 'expense' : 'sale',
                'category' => $definition->type,
                'description' => $definition->description,
                'payment_method' => $definition->payment_method,
                'effect_type' => $definition->effect_type,
                'source_wallet' => $definition->source_wallet,
                'destination_wallet' => $definition->destination_wallet,
                'recorded_by' => optional($request->user())->id,
            ]);
        });
        return response()->json(['data' => $entry], $entry->wasRecentlyCreated ? 201 : 200);
    }

    public function cashTopUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'destination_wallet' => ['required', 'in:cash,gcash,bpi,landbank'],
            'entry_date' => ['required', 'date'],
            'reference' => ['required', 'string', 'max:100'],
            'notes' => ['required', 'string', 'min:3', 'max:1000'],
            'idempotency_key' => ['required', 'uuid'],
            'confirmation' => ['required', 'in:ADD WALLET TOP UP'],
            'cash_breakdown' => ['nullable', 'array', 'size:10'],
            'cash_breakdown.*.denomination' => ['required_with:cash_breakdown', 'integer', 'in:1000,500,200,100,50,20,10,5,1'],
            'cash_breakdown.*.kind' => ['required_with:cash_breakdown', 'in:bill,coin'],
            'cash_breakdown.*.count' => ['required_with:cash_breakdown', 'integer', 'min:0', 'max:100000'],
        ]);

        if ($data['destination_wallet'] === 'cash' && isset($data['cash_breakdown'])) {
            $data['cash_breakdown'] = app(CashDenominationService::class)->normalize($data['cash_breakdown']);
            app(CashDenominationService::class)->assertEqualsAmount($data['cash_breakdown'], $data['amount']);
        } else {
            $data['cash_breakdown'] = null;
        }

        $entry = DB::transaction(function () use ($data, $request): FinancialEntry {
            $existing = FinancialEntry::where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) return $existing;

            $methods = ['cash' => 'add_to_cash', 'gcash' => 'add_to_gcash', 'bpi' => 'deposit_to_bpi', 'landbank' => 'deposit_to_landbank'];
            $labels = ['cash' => 'Cash', 'gcash' => 'GCash', 'bpi' => 'BPI', 'landbank' => 'Landbank'];
            $wallet = $data['destination_wallet'];

            return FinancialEntry::create([
                'transaction_definition_id' => null,
                'type' => 'sale',
                'category' => 'Super Admin '.$labels[$wallet].' Top-up',
                'description' => trim($data['notes']),
                'amount' => $data['amount'],
                'cash_breakdown' => $data['cash_breakdown'],
                'entry_date' => $data['entry_date'],
                'payment_method' => $methods[$wallet],
                'effect_type' => 'cash_in',
                'source_wallet' => null,
                'destination_wallet' => $wallet,
                'reference' => trim($data['reference']),
                'notes' => trim($data['notes']),
                'idempotency_key' => $data['idempotency_key'],
                'recorded_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'message' => 'Wallet top-up recorded in the Daily Operations ledger.',
            'data' => $entry->load('recorder:id,name'),
        ], $entry->wasRecentlyCreated ? 201 : 200);
    }

    private function walletFor(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'cash', 'add_to_cash' => 'cash',
            'bank_bpi', 'deposit_to_bpi', 'bank', 'bank_transfer', 'transfer' => 'bpi',
            'bank_landbank', 'deposit_to_landbank' => 'landbank',
            'gcash', 'ewallet', 'e_wallet', 'mobile_money', 'maya', 'paymaya', 'add_to_gcash' => 'gcash',
            'paymongo' => 'paymongo',
            default => 'other',
        };
    }
}
