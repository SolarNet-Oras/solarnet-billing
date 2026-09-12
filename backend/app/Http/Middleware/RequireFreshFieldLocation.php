<?php

namespace App\Http\Middleware;

use App\Models\StaffLiveLocation;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        // staff_live_locations.captured_at is a PostgreSQL timestamp without a
        // timezone. Compare it with PostgreSQL's own UTC clock so PHP, the app,
        // and database session timezones cannot shift an otherwise fresh point.
        $location = StaffLiveLocation::query()
            ->where('user_id', $user->id)
            ->where('sharing_enabled', true)
            ->whereNotNull('accuracy_meters')
            ->where('accuracy_meters', '<=', 100)
            ->where('captured_at', '>=', DB::raw("(CURRENT_TIMESTAMP AT TIME ZONE 'UTC') - INTERVAL '5 minutes'"))
            ->where('captured_at', '<=', DB::raw("(CURRENT_TIMESTAMP AT TIME ZONE 'UTC') + INTERVAL '1 minute'"))
            ->first();

        if (! $location) {
            return $this->locationRequired(
                'A current work location is required. Enable location, keep the SolarNet staff app open, and try again.'
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
                'maximum_accuracy_meters' => 100,
            ],
        ], 428);
    }
}
