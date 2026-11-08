<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOperationalTenantContext
{
    public function __construct(
        private readonly ResolveSaasTenantContext $tenantResolver,
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $host = mb_strtolower($request->getHost());
        $appHost = mb_strtolower((string) config('saas.domains.app'));
        $billingHost = mb_strtolower((string) config('saas.domains.billing'));

        if ($host === $appHost) {
            return $this->tenantResolver->handle($request, $next);
        }

        $enforced = (bool) config('saas.enabled')
            && (bool) config('saas.domains_enabled')
            && config('saas.enforcement_mode') === 'enforced';

        if ($host !== $billingHost && $enforced) {
            return new JsonResponse(['message' => 'Operational APIs are unavailable on this domain.'], 404);
        }

        $tenant = Tenant::query()
            ->where('slug', config('saas.primary_tenant_slug'))
            ->where('is_primary', true)
            ->where('status', 'active')
            ->first();
        $user = $request->user('api');

        // Preserve the existing private billing surface while global SaaS
        // enforcement is disabled. The context still gives new records the
        // correct primary ownership, but legacy authorization remains intact.
        if (! $enforced) {
            if ($tenant) {
                $this->currentTenant->set($tenant);
                $request->attributes->set('saas_tenant', $tenant);
            }

            return $next($request);
        }

        if (! $tenant || ! $user || ! TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists()) {
            return new JsonResponse(['message' => 'No active SolarNet workspace membership exists.'], 403);
        }

        $this->currentTenant->set($tenant);
        $request->attributes->set('saas_tenant', $tenant);

        return $next($request);
    }
}
