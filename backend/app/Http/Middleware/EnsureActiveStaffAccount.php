<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveStaffAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('api');

        if (! $user || ! $user->is_active) {
            auth('api')->logout();

            return response()->json([
                'status' => 'error',
                'message' => 'This staff account is disabled. Please contact the Super Administrator.',
            ], 403);
        }

        return $next($request);
    }
}
