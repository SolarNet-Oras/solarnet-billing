<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\SaasTenantAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class SaasTenantAuthController extends Controller
{
    public function __construct(private readonly SaasTenantAccessService $access) {}

    public function login(Request $request): JsonResponse
    {
        if (! config('saas.tenant_app_enabled')
            && (! config('saas.domains_enabled') || ! config('saas.enabled'))) {
            return response()->json(['message' => 'Tenant sign-in is not enabled yet.'], 503);
        }

        if (mb_strtolower($request->getHost()) !== mb_strtolower((string) config('saas.domains.app'))) {
            return response()->json(['message' => 'Tenant sign-in is available only on the tenant application domain.'], 404);
        }

        $data = $request->validate([
            'tenant' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $tenant = Tenant::query()
            ->with('subscription')
            ->where('slug', Str::lower(trim($data['tenant'])))
            ->where('status', 'active')
            ->first();

        // SolarNet's primary workspace remains exclusively on the private
        // billing domain. The shared tenant application is for subscribed
        // ISP companies only, even when SolarNet memberships exist for data
        // ownership and staff authorization metadata.
        if ($tenant?->is_primary) {
            return response()->json(['message' => 'The tenant or credentials are invalid.'], 401);
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim($data['email']))])
            ->where('is_active', true)
            ->first();

        if (! $tenant || ! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json(['message' => 'The tenant or credentials are invalid.'], 401);
        }

        $membership = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'The tenant or credentials are invalid.'], 401);
        }

        $decision = $this->access->evaluate($tenant);
        if (! $decision['allowed']) {
            return response()->json([
                'message' => 'This tenant subscription cannot access the application.',
                'reason' => $decision['reason'],
            ], 402);
        }

        $token = JWTAuth::claims(['tenant_id' => $tenant->id])->fromUser($user);

        return response()->json([
            'token_type' => 'bearer',
            'access_token' => $token,
            'expires_in' => config('jwt.ttl') * 60,
            'tenant' => $tenant->only(['id', 'slug', 'name']),
            'membership' => $membership->only(['membership_role', 'is_owner']),
            'subscription_access' => $decision,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->attributes->get('saas_tenant');
        /** @var TenantMembership $membership */
        $membership = $request->attributes->get('saas_membership');
        $user = $request->user('api');

        return response()->json([
            'user' => $user?->only(['id', 'name', 'email']),
            'tenant' => $tenant->only(['id', 'slug', 'name']),
            'membership' => $membership->only(['membership_role', 'is_owner']),
            'subscription_access' => $request->attributes->get('saas_subscription_access'),
        ]);
    }
}
