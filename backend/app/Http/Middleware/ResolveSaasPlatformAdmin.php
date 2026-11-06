<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantMembership;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class ResolveSaasPlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('saas.platform_enabled')) {
            return new JsonResponse(['message' => 'The SaaS platform control plane is not enabled yet.'], 503);
        }
        if (mb_strtolower($request->getHost()) !== mb_strtolower((string) config('saas.domains.admin'))) {
            return new JsonResponse(['message' => 'Platform administration is available only on the SaaS admin domain.'], 404);
        }

        $payload = JWTAuth::parseToken()->getPayload();
        $user = $request->user('api');
        $tenantId = $payload->get('tenant_id');
        if (! $user || $payload->get('platform_admin') !== true || ! is_string($tenantId) || ! $user->hasRole('super_admin')) {
            return new JsonResponse(['message' => 'The platform administrator context is invalid.'], 403);
        }

        $tenant = Tenant::query()->whereKey($tenantId)->where('is_primary', true)->where('subscription_exempt', true)->where('status', 'active')->first();
        $membership = $tenant ? TenantMembership::query()
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('status', 'active')->where('is_owner', true)->first() : null;
        if (! $tenant || ! $membership) {
            return new JsonResponse(['message' => 'An active SolarNet owner membership is required.'], 403);
        }

        $request->attributes->set('platform_tenant', $tenant);
        $request->attributes->set('platform_membership', $membership);

        return $next($request);
    }
}
