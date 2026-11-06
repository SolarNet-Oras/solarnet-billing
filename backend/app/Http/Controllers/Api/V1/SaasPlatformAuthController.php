<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

class SaasPlatformAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        if (! config('saas.platform_enabled')) {
            return response()->json(['message' => 'The SaaS platform control plane is not enabled yet.'], 503);
        }
        if (mb_strtolower($request->getHost()) !== mb_strtolower((string) config('saas.domains.admin'))) {
            return response()->json(['message' => 'Platform sign-in is available only on the SaaS admin domain.'], 404);
        }
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        $user = User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($data['email']))])->where('is_active', true)->first();
        $tenant = Tenant::query()->where('is_primary', true)->where('subscription_exempt', true)->where('status', 'active')->first();
        $membership = $user && $tenant ? TenantMembership::query()
            ->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->where('status', 'active')->where('is_owner', true)->first() : null;

        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->hasRole('super_admin') || ! $tenant || ! $membership) {
            return response()->json(['message' => 'The platform administrator credentials are invalid.'], 401);
        }
        $token = JWTAuth::claims(['tenant_id' => $tenant->id, 'platform_admin' => true])->fromUser($user);

        return response()->json([
            'token_type' => 'bearer', 'access_token' => $token, 'expires_in' => config('jwt.ttl') * 60,
            'user' => $user->only(['id', 'name', 'email']),
            'platform_tenant' => $tenant->only(['id', 'slug', 'name']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user('api')?->only(['id', 'name', 'email']),
            'platform_tenant' => $request->attributes->get('platform_tenant')?->only(['id', 'slug', 'name']),
        ]);
    }
}
