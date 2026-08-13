<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FinancialEntry;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $date = $request->date('date')?->toDateString() ?? now()->toDateString();
        $entries = FinancialEntry::whereDate('entry_date', $date)->latest()->get();
        $collections = Payment::with('customer:id,full_name,account_number')->whereDate('payment_date', $date)->latest()->get();
        $wallets = collect(['cash', 'ewallet', 'bank'])->mapWithKeys(fn (string $wallet) => [$wallet => ['collections' => 0.0, 'sales' => 0.0, 'expenses' => 0.0, 'balance' => 0.0]])->all();
        foreach ($collections as $collection) {
            $wallet = $this->walletFor($collection->payment_method);
            if (isset($wallets[$wallet])) $wallets[$wallet]['collections'] += (float) $collection->amount;
        }
        foreach ($entries as $entry) {
            $wallet = $this->walletFor($entry->payment_method);
            if (isset($wallets[$wallet])) $wallets[$wallet][$entry->type === 'expense' ? 'expenses' : 'sales'] += (float) $entry->amount;
        }
        foreach ($wallets as &$wallet) $wallet['balance'] = $wallet['collections'] + $wallet['sales'] - $wallet['expenses'];
        return response()->json(['data' => ['date' => $date, 'collections' => $collections, 'sales' => $entries->where('type', 'sale')->values(), 'expenses' => $entries->where('type', 'expense')->values(), 'wallets' => $wallets, 'totals' => ['collections' => (float) $collections->sum('amount'), 'sales' => (float) $entries->where('type', 'sale')->sum('amount'), 'expenses' => (float) $entries->where('type', 'expense')->sum('amount')]]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => 'required|in:sale,expense', 'description' => 'required|string|max:255', 'category' => 'nullable|string|max:100', 'amount' => 'required|numeric|min:0.01', 'entry_date' => 'required|date', 'payment_method' => 'required|in:cash,gcash,bank,bank_bpi,bank_landbank,add_to_cash,add_to_gcash,deposit_to_bpi,deposit_to_landbank,other', 'reference' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:1000']);
        $data['recorded_by'] = optional($request->user())->id;
        return response()->json(['data' => FinancialEntry::create($data)], 201);
    }

    private function walletFor(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'cash', 'add_to_cash' => 'cash',
            'bank', 'bank_transfer', 'transfer', 'bank_bpi', 'bank_landbank', 'deposit_to_bpi', 'deposit_to_landbank' => 'bank',
            'gcash', 'ewallet', 'e_wallet', 'mobile_money', 'maya', 'paymaya', 'add_to_gcash' => 'ewallet',
            default => 'other',
        };
    }
}
