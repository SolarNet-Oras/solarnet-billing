<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CustomerRegistrationImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CustomerRegistrationImportController extends Controller
{
    private const PREFIX = 'customer-registration-import:';

    public function preview(Request $request, CustomerRegistrationImportService $service): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240']);
        try { $preview = $service->previewUploadedFile($request->file('file')); }
        catch (InvalidArgumentException $e) { return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422); }
        $token = (string) Str::uuid();
        Cache::put(self::PREFIX.$token, ['user_id' => (string) $request->user()->getAuthIdentifier(), 'rows' => $preview['rows']], now()->addMinutes(30));
        return response()->json(['status' => 'success', 'message' => 'Registration preview ready.', 'preview_token' => $token, 'expires_in_minutes' => 30, ...$preview]);
    }

    public function apply(Request $request, CustomerRegistrationImportService $service): JsonResponse
    {
        $validated = $request->validate(['preview_token' => 'required|uuid']);
        $key = self::PREFIX.$validated['preview_token'];
        $payload = Cache::get($key);
        if (! is_array($payload) || ($payload['user_id'] ?? null) !== (string) $request->user()->getAuthIdentifier())
            return response()->json(['status' => 'error', 'message' => 'Preview expired or belongs to another user. Generate it again.'], 422);
        $result = $service->apply($payload['rows'] ?? []);
        Cache::forget($key);
        Log::info('Bulk customer registration applied', ['user_id' => $request->user()->getAuthIdentifier(), ...$result]);
        return response()->json(['status' => 'success', 'message' => "{$result['created']} new customer profile(s) registered. Existing names were not changed.", 'data' => $result]);
    }
}
