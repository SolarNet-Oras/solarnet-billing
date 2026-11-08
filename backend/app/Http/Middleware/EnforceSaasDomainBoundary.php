<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceSaasDomainBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = mb_strtolower($request->getHost());
        $appHost = mb_strtolower((string) config('saas.domains.app'));
        $adminHost = mb_strtolower((string) config('saas.domains.admin'));

        if (! in_array($host, [$appHost, $adminHost], true)) {
            return $next($request);
        }

        if ($request->is('api/v1/saas/domain-context')
            || $request->is('api/v1/saas/auth/*')
            || $request->is('api/v1/saas/tenant/staff-access-requests*')
            || $request->is('api/v1/saas/tenant/subscription*')
            || $request->is('api/v1/saas/tenant/cashout-requests*')
            || $request->is('api/v1/saas/applications*')
            || $request->is('api/v1/saas/platform/*')
            || $request->is('api/health')) {
            return $next($request);
        }

        $ready = (bool) config('saas.domains_enabled', false)
            && (bool) config('saas.enabled', false)
            && config('saas.enforcement_mode') === 'enforced';

        if (! $ready) {
            return new JsonResponse([
                'message' => 'This SolarNet SaaS surface is staged and cannot access billing data yet.',
                'surface' => $host === $adminHost ? 'admin' : 'app',
            ], 503);
        }

        if ($host === $adminHost) {
            return new JsonResponse([
                'message' => 'Operational tenant APIs are unavailable on the platform administration domain.',
                'surface' => 'admin',
            ], 404);
        }

        if (config('saas.workspace_activation_mode') === 'canary'
            && config('saas.workspace_canary_read_only', true)
            && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return new JsonResponse([
                'message' => 'The tenant canary workspace is read-only while isolation is being verified.',
                'surface' => 'app',
            ], 423);
        }

        // Operational routes apply tenant.operational after authentication.
        // Public routes remain explicitly allow-listed above.
        return $next($request);
    }
}
