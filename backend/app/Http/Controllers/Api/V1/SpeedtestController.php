<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SpeedtestConnectionPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpeedtestController extends Controller
{
    public function __construct(private readonly SpeedtestConnectionPresenter $presenter)
    {
    }

    /**
     * Return identity data for this request only. It is deliberately never
     * cached: a shared cache must not show one visitor's IP to another visitor.
     */
    public function connection(Request $request): JsonResponse
    {
        if (!$this->setting('speedtest.enabled', config('speedtest.enabled'))) {
            return $this->privateNoStore(response()->json([
                'status' => 'error',
                'message' => 'SolarNet Speedtest is currently unavailable.',
            ], 503));
        }

        $providerName = (string) $this->setting('speedtest.provider_display_name', config('speedtest.provider_name'));

        return $this->privateNoStore(response()->json([
            'status' => 'success',
            'data' => $this->presenter->present($request->ip(), $providerName),
        ]));
    }

    private function privateNoStore(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Cache-Control', 'private, no-store, max-age=0, must-revalidate')
            ->header('Pragma', 'no-cache')
            ->header('Vary', 'X-Forwarded-For');
    }

    /**
     * Do not cache a missing override. A deployment that changes
     * SPEEDTEST_PROVIDER_NAME must take effect for new requests even when the
     * database has no administrator override yet.
     */
    private function setting(string $key, mixed $default): mixed
    {
        $setting = Setting::query()->where('key', $key)->first();

        return $setting?->typedValue() ?? $default;
    }
}
