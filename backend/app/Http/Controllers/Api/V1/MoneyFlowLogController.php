<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MoneyFlowLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MoneyFlowLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'payment_method' => ['nullable', 'string', 'max:32'],
            'event_type' => ['nullable', 'string', 'max:64'],
        ]);

        $logs = MoneyFlowLog::query()
            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($q, $date) => $q->whereDate('occurred_at', '<=', $date))
            ->when($data['payment_method'] ?? null, fn ($q, $method) => $q->where('payment_method', $method))
            ->when($data['event_type'] ?? null, fn ($q, $event) => $q->where('event_type', $event))
            ->latest('occurred_at')
            ->latest('created_at')
            ->paginate(min((int) $request->input('per_page', 100), 500));

        return response()->json($logs);
    }
}
