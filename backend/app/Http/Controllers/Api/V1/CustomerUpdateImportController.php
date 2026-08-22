<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CustomerUpdateImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CustomerUpdateImportController extends Controller
{
    private const CACHE_PREFIX = 'customer-update-import:';

    public function preview(Request $request, CustomerUpdateImportService $import): JsonResponse
    {
        $validated = $request->validate([
            'file' => 'nullable|file|mimes:xlsx,xls,csv,txt|max:10240',
            'google_sheet_url' => 'nullable|string|max:2000',
        ]);

        $file = $request->file('file');
        $googleSheetUrl = trim((string) ($validated['google_sheet_url'] ?? ''));
        if ((! $file && $googleSheetUrl === '') || ($file && $googleSheetUrl !== '')) {
            return response()->json([
                'status' => 'error',
                'message' => 'Choose exactly one source: an XLSX/XLS/CSV file or one shared Google Sheet URL.',
            ], 422);
        }

        try {
            $preview = $file
                ? $import->previewUploadedFile($file)
                : $import->previewGoogleSheet($googleSheetUrl);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 422);
        }

        $token = (string) Str::uuid();
        Cache::put(self::CACHE_PREFIX . $token, [
            'user_id' => (string) $request->user()->getAuthIdentifier(),
            'source_label' => $preview['source_label'],
            'rows' => $preview['rows'],
        ], now()->addMinutes(30));

        return response()->json([
            'status' => 'success',
            'message' => 'Preview ready. Review the exact matches before applying.',
            'preview_token' => $token,
            'expires_in_minutes' => 30,
            ...$preview,
        ]);
    }

    public function apply(Request $request, CustomerUpdateImportService $import): JsonResponse
    {
        $validated = $request->validate([
            'preview_token' => 'required|uuid',
        ]);

        $key = self::CACHE_PREFIX . $validated['preview_token'];
        $payload = Cache::get($key);
        if (! is_array($payload) || ($payload['user_id'] ?? null) !== (string) $request->user()->getAuthIdentifier()) {
            return response()->json([
                'status' => 'error',
                'message' => 'This preview has expired or belongs to another user. Generate a new preview before applying changes.',
            ], 422);
        }

        $result = $import->apply($payload['rows'] ?? []);
        Cache::forget($key);

        Log::info('Customer profile spreadsheet import applied', [
            'user_id' => $request->user()->getAuthIdentifier(),
            'source' => $payload['source_label'] ?? 'unknown',
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
            'skipped' => count($result['skipped']),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "{$result['updated']} customer profile(s) updated. MAC addresses, network settings, balances, invoices, plans, and installation dates were not changed.",
            'data' => $result,
        ]);
    }
}
