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

        // Fail closed even after flags are changed. A later rollout must
        // replace this guard with verified platform/tenant middleware before
        // either hostname can reach application APIs.
        return new JsonResponse([
            'message' => 'Tenant authorization is not installed for this surface.',
            'surface' => $host === $adminHost ? 'admin' : 'app',
        ], 503);
    }
}
