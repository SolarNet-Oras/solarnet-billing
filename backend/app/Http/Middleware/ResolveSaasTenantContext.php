<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\SaasTenantAccessService;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Symfony\Component\HttpFoundation\Response;

class ResolveSaasTenantContext
{
    public function __construct(
        private readonly SaasTenantAccessService $access,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (mb_strtolower($request->getHost()) !== mb_strtolower((string) config('saas.domains.app'))) {
            return new JsonResponse(['message' => 'Tenant authentication is valid only on the tenant application domain.'], 404);
        }

        $tenantId = JWTAuth::parseToken()->getPayload()->get('tenant_id');
        $user = $request->user('api');

        if (! is_string($tenantId) || ! $user) {
            return new JsonResponse(['message' => 'The tenant authentication context is invalid.'], 403);
        }

        $tenant = Tenant::query()->with('subscription')->whereKey($tenantId)->where('status', 'active')->first();
        $membership = $tenant ? TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first() : null;

        if (! $tenant || ! $membership) {
            return new JsonResponse(['message' => 'No active membership exists for this tenant.'], 403);
        }

        $decision = $this->access->evaluate($tenant);
        if (! $decision['allowed']) {
            return new JsonResponse([
                'message' => 'This tenant subscription cannot access the application.',
                'reason' => $decision['reason'],
            ], 402);
        }

        $this->currentTenant->set($tenant);

        $request->attributes->set('saas_tenant', $tenant);
        $request->attributes->set('saas_membership', $membership);
        $request->attributes->set('saas_subscription_access', $decision);

        return $next($request);
    }
}
