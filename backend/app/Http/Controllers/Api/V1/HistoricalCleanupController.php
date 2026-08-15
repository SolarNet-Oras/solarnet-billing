<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HistoricalCleanupAudit;
use App\Services\HistoricalDataCleanupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HistoricalCleanupController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => HistoricalCleanupAudit::query()->with('user:id,name,email')->latest()->paginate(20),
        ]);
    }

    public function preview(Request $request, HistoricalDataCleanupService $cleanup): JsonResponse
    {
        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['string', Rule::in(array_keys(HistoricalDataCleanupService::MODULES))],
        ]);

        try {
            return response()->json(['status' => 'success', 'data' => $cleanup->preview($request->user(), $data)]);
        } catch (\RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }
    }

    public function execute(Request $request, HistoricalDataCleanupService $cleanup): JsonResponse
    {
        $data = $request->validate([
            'preview_token' => ['required', 'uuid'],
            'confirmation' => ['required', 'string'],
        ]);

        try {
            $audit = $cleanup->execute($request->user(), $data['preview_token'], $data['confirmation'], $request->ip());
            return response()->json(['status' => 'success', 'data' => $audit]);
        } catch (\RuntimeException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }
    }
}
