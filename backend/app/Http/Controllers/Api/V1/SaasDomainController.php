<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaasDomainController extends Controller
{
    public function context(Request $request): JsonResponse
    {
        $host = mb_strtolower($request->getHost());
        $domains = collect(config('saas.domains', []))
            ->map(fn ($domain): string => mb_strtolower((string) $domain));
        $surface = $domains->search($host, strict: true);

        return response()->json([
            'surface' => $surface === false ? 'public' : $surface,
            'host' => $host,
            'domains_enabled' => (bool) config('saas.domains_enabled', false),
            'saas_enabled' => (bool) config('saas.enabled', false),
            'registration_enabled' => (bool) config('saas.registration_enabled', false),
            'enforcement_mode' => (string) config('saas.enforcement_mode', 'disabled'),
        ]);
    }
}
