<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinancialEntry;
use App\Models\Payment;
use App\Models\TransactionDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinancialEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        $entries = FinancialEntry::whereDate('entry_date', $date)->latest()->get();
        $collections = Payment::with('customer:id,full_name,account_number')->whereDate('payment_date', $date)->latest()->get();
        $wallets = collect(['cash', 'gcash', 'bpi', 'landbank'])->mapWithKeys(fn (string $wallet) => [$wallet => ['collections' => 0.0, 'cash_in' => 0.0, 'transfers_in' => 0.0, 'transfers_out' => 0.0, 'expenses' => 0.0, 'balance' => 0.0]])->all();

        foreach ($collections as $collection) {
            $wallet = $this->walletFor($collection->payment_method);
            if (isset($wallets[$wallet])) $wallets[$wallet]['collections'] += (float) $collection->amount;
        }
        foreach ($entries as $entry) {
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
        foreach ($wallets as &$wallet) $wallet['balance'] = $wallet['collections'] + $wallet['cash_in'] + $wallet['transfers_in'] - $wallet['transfers_out'] - $wallet['expenses'];

        return response()->json(['data' => [
            'date' => $date,
            'collections' => $collections,
            'cash_in' => $entries->filter(fn (FinancialEntry $entry) => ($entry->effect_type ?: ($entry->type === 'expense' ? 'expense' : 'cash_in')) === 'cash_in')->values(),
            'transfers' => $entries->where('effect_type', 'transfer')->values(),
            'expenses' => $entries->filter(fn (FinancialEntry $entry) => ($entry->effect_type ?: ($entry->type === 'expense' ? 'expense' : 'cash_in')) === 'expense')->values(),
            'wallets' => $wallets,
        ]]);
    }

    public function definitions(Request $request): JsonResponse
    {
        $query = TransactionDefinition::query()->where('active', true);
        if ($request->filled('type')) $query->where('type', $request->string('type'));
        if ($request->filled('description')) $query->where('description', $request->string('description'));
        return response()->json(['data' => $query->orderBy('sort_order')->get(['id', 'type', 'description', 'payment_method'])]);
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
        ]);
        $definition = TransactionDefinition::query()->whereKey($data['transaction_definition_id'])->where('active', true)->first();
        if (!$definition) return response()->json(['message' => 'The selected transaction type, description, and payment method is not valid.'], 422);

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

    private function walletFor(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'cash', 'add_to_cash' => 'cash',
            'bank_bpi', 'deposit_to_bpi', 'bank', 'bank_transfer', 'transfer' => 'bpi',
            'bank_landbank', 'deposit_to_landbank' => 'landbank',
            'gcash', 'ewallet', 'e_wallet', 'mobile_money', 'maya', 'paymaya', 'add_to_gcash' => 'gcash',
            default => 'other',
        };
    }
}
