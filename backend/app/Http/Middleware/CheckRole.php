<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string  ...$roles
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!auth()->check()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated',
            ], 401);
        }

        $user = auth()->user();
        $membership = $request->attributes->get('saas_membership');

        // Support pipe-separated role alternatives, e.g. 'role:admin|super_admin'
        $roleNames = [];
        foreach ($roles as $role) {
            foreach (explode('|', $role) as $roleName) {
                $roleNames[] = $roleName;
            }
        }

        // Check if user has any of the required roles
        $tenantRoleAliases = $membership
            ? config('saas_tenant_workspace.membership_role_aliases.'.(string) $membership->membership_role, [])
            : [];

        if (!$user->hasAnyRole($roleNames) && empty(array_intersect($roleNames, $tenantRoleAliases))) {
            return response()->json([
                'status' => 'error',
                'message' => 'Insufficient permissions. Required role: ' . implode(' or ', $roles),
            ], 403);
        }

        return $next($request);
    }
}
