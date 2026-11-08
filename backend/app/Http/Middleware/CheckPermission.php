<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$permissions
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        if (!auth()->check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user = auth()->user();
        $membership = $request->attributes->get('saas_membership');
        $tenantPermissions = $membership
            ? config('saas_tenant_workspace.membership_permissions.'.(string) $membership->membership_role, [])
            : [];

        // Each parameter is required (AND). Within a parameter, pipe-separated
        // permissions are alternatives (OR), e.g. 'edit-tickets|close-tickets'.
        foreach ($permissions as $permission) {
            $alternatives = explode('|', $permission);
            $hasAny = false;

            foreach ($alternatives as $alternative) {
                if ($user->hasPermission($alternative) || in_array($alternative, $tenantPermissions, true)) {
                    $hasAny = true;
                    break;
                }
            }

            if (!$hasAny) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Insufficient permissions. Required permission: ' . $permission,
                ], 403);
            }
        }

        return $next($request);
    }
}
