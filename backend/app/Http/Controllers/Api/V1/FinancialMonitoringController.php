<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\FinancialMonitoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialMonitoringController extends Controller
{
    /**
     * Role-gated by the route. This endpoint is intentionally read-only and
     * does not expose a financial adjustment or AI write operation.
     */
    public function index(Request $request, FinancialMonitoringService $monitoring): JsonResponse
    {
        $data = $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        return response()->json([
            'data' => $monitoring->summary($data['month'] ?? null),
        ]);
    }
}
