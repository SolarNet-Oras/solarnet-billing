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
        return response()->json(['data' => ['date' => $date, 'collections' => $collections, 'sales' => $entries->where('type', 'sale')->values(), 'expenses' => $entries->where('type', 'expense')->values(), 'totals' => ['collections' => (float) $collections->sum('amount'), 'sales' => (float) $entries->where('type', 'sale')->sum('amount'), 'expenses' => (float) $entries->where('type', 'expense')->sum('amount')]]]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => 'required|in:sale,expense', 'description' => 'required|string|max:255', 'category' => 'nullable|string|max:100', 'amount' => 'required|numeric|min:0.01', 'entry_date' => 'required|date', 'payment_method' => 'required|in:cash,gcash,bank,other', 'reference' => 'nullable|string|max:100', 'notes' => 'nullable|string|max:1000']);
        $data['recorded_by'] = optional($request->user())->id;
        return response()->json(['data' => FinancialEntry::create($data)], 201);
    }
}
