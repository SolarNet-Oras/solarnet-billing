<?php

namespace App\Http\Middleware;

use App\Models\StaffLiveLocation;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RequireFreshFieldLocation
{
    /**
     * Require a recent, device-reported position before a field worker records work.
     * Tracking is enforced only during the disclosed 06:00-18:00 Asia/Manila shift.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasAnyRole(['collector', 'technician'])) {
            return $next($request);
        }

        $workNow = now('Asia/Manila');
        if ($workNow->hour < 6 || $workNow->hour >= 18) {
            return $next($request);
        }

        if (! Schema::hasTable('staff_live_locations')) {
            return $this->locationRequired(
                'Work-location storage is not installed. Ask an administrator to run the pending database migrations.'
            );
        }

        $location = StaffLiveLocation::query()
            ->where('user_id', $user->id)
            ->where('sharing_enabled', true)
            ->where('captured_at', '>=', now()->subMinutes(5))
            ->first();

        if (! $location) {
            return $this->locationRequired(
                'A current work location is required from 6:00 AM to 6:00 PM. Allow precise location, keep the SolarNet staff app open, and try again after it reports your position.'
            );
        }

        return $next($request);
    }

    private function locationRequired(string $message): JsonResponse
    {
        return response()->json([
            'status' => 'location_required',
            'message' => $message,
            'tracking_window' => [
                'timezone' => 'Asia/Manila',
                'starts_at' => '06:00',
                'ends_at' => '18:00',
                'maximum_age_minutes' => 5,
            ],
        ], 428);
    }
}
