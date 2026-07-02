<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ServicePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ServicePlanController extends Controller
{
    /**
     * Display a listing of service plans
     */
    public function index(): JsonResponse
    {
        $plans = ServicePlan::withCount('customers')
            ->orderBy('created_at', 'desc')
            ->get();
        
        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    /**
     * Public listing used by the self-signup page.
     * Returns only active plans and only the fields safe to expose publicly.
     */
    public function publicIndex(): JsonResponse
    {
        $plans = ServicePlan::where('is_active', true)
            ->orderBy('price')
            ->get(['id', 'name', 'description', 'download_speed', 'upload_speed', 'price']);
        return response()->json([
            'success' => true,
            'data' => $plans,
        ]);
    }

    /**
     * Store a newly created service plan
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'description' => 'nullable|string',
            'download_speed' => 'required|integer|min:1',
            'upload_speed' => 'required|integer|min:1',
            'burst_download' => 'nullable|integer|min:0',
            'burst_upload' => 'nullable|integer|min:0',
            'burst_threshold' => 'nullable|integer|min:0',
            'burst_time' => 'nullable|integer|min:1',
            'priority' => 'required|integer|min:1|max:8',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $plan = ServicePlan::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Service plan created successfully',
            'data' => $plan,
        ], 201);
    }

    /**
     * Display the specified service plan
     */
    public function show(string $id): JsonResponse
    {
        try {
            $plan = ServicePlan::withCount('customers')->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service plan not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $plan,
        ]);
    }

    /**
     * Update the specified service plan
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $plan = ServicePlan::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service plan not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'price' => 'sometimes|required|numeric|min:0',
            'description' => 'nullable|string',
            'download_speed' => 'sometimes|required|integer|min:1',
            'upload_speed' => 'sometimes|required|integer|min:1',
            'burst_download' => 'nullable|integer|min:0',
            'burst_upload' => 'nullable|integer|min:0',
            'burst_threshold' => 'nullable|integer|min:0',
            'burst_time' => 'nullable|integer|min:1',
            'priority' => 'sometimes|required|integer|min:1|max:8',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $plan->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Service plan updated successfully',
            'data' => $plan,
        ]);
    }

    /**
     * Remove the specified service plan
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $plan = ServicePlan::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Service plan not found',
            ], 404);
        }

        // Check if plan is in use
        if ($plan->customers()->count() > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete service plan that is assigned to customers',
            ], 422);
        }

        $plan->delete();

        return response()->json([
            'success' => true,
            'message' => 'Service plan deleted successfully',
        ]);
    }
}
