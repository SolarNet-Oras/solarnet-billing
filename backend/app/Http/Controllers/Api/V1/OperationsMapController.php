<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OperationsMapAsset;
use App\Models\Payment;
use App\Models\StaffLiveLocation;
use App\Models\StaffLocationHistory;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OperationsMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationsMapController extends Controller
{
    public function index(Request $request, OperationsMapService $operationsMap): JsonResponse
    {
        $data = $operationsMap->snapshot();
        $canViewStaff = Schema::hasTable('staff_live_locations')
            && $request->user()->hasAnyRole(['super_admin', 'admin']);

        if (! $canViewStaff) {
            $data['staff_locations'] = [];

            return response()->json(['data' => $data]);
        }

        $locations = StaffLiveLocation::query()
                ->where('sharing_enabled', true)
                ->where('captured_at', '>=', now()->subMinutes(5))
                ->where('captured_at', '<=', now()->addMinute())
                ->with('user.roles:id,name')
                ->latest('captured_at')
                ->get();
        $allLatestLocations = StaffLiveLocation::query()->get()->keyBy('user_id');
        $fieldUsers = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', ['collector', 'technician']))
            ->with('roles:id,name')
            ->orderBy('name')
            ->get();
        $workNow = now('Asia/Manila');
        $insideWorkHours = $workNow->hour >= 6 && $workNow->hour < 18;
        $data['tracking_policy'] = [
            'timezone' => 'Asia/Manila',
            'starts_at' => '06:00',
            'ends_at' => '18:00',
            'inside_work_hours' => $insideWorkHours,
        ];
        $data['staff_tracking_status'] = $fieldUsers->map(function (User $user) use ($allLatestLocations, $insideWorkHours): array {
            $latest = $allLatestLocations->get($user->id);
            $capturedAt = $latest?->captured_at;
            $minutesSinceUpdate = $capturedAt?->diffInMinutes(now(), false);
            $isFresh = $capturedAt !== null
                && $capturedAt->between(now()->subMinutes(5), now()->addMinute());
            $state = ! $insideWorkHours
                ? 'off_duty'
                : ($isFresh ? 'reporting' : 'missing');

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'role' => $user->roles->pluck('name')->intersect(['collector', 'technician'])->first(),
                'state' => $state,
                'last_captured_at' => $latest?->captured_at?->toIso8601String(),
                'minutes_since_update' => $minutesSinceUpdate !== null ? max(0, $minutesSinceUpdate) : null,
            ];
        })->values();
        $staffIds = $locations->pluck('user_id');
        $activeTickets = Ticket::query()
            ->whereIn('assigned_to', $staffIds)
            ->whereNotIn('status', ['resolved', 'closed'])
            ->with('customer:id,full_name,address')
            ->latest('updated_at')
            ->get()
            ->unique('assigned_to')
            ->keyBy('assigned_to');
        $todayCollections = Payment::query()
            ->whereIn('collector_id', $staffIds)
            ->whereDate('payment_date', today())
            ->selectRaw('collector_id, COUNT(*) as payment_count, COALESCE(SUM(amount), 0) as payment_total')
            ->groupBy('collector_id')
            ->get()
            ->keyBy('collector_id');

        $data['staff_locations'] = $locations
                ->map(function (StaffLiveLocation $location) use ($activeTickets, $todayCollections): array {
                    $role = $location->user?->roles->pluck('name')->intersect(['collector', 'technician'])->first();
                    $ticket = $activeTickets->get($location->user_id);
                    $collections = $todayCollections->get($location->user_id);
                    $activity = $role === 'technician' && $ticket
                        ? [
                            'label' => $ticket->status === 'in_progress' ? 'Working on assigned ticket' : 'Assigned ticket pending',
                            'detail' => trim($ticket->ticket_number.' · '.($ticket->customer?->full_name ?: 'Customer unavailable')),
                            'reference' => $ticket->ticket_number,
                            'customer' => $ticket->customer?->full_name,
                            'address' => $ticket->customer?->address,
                        ]
                        : ($role === 'collector'
                            ? [
                                'label' => ((int) ($collections?->payment_count ?? 0)) > 0 ? 'Field collection activity' : 'Available for collection duty',
                                'detail' => ((int) ($collections?->payment_count ?? 0)).' payment(s) recorded today · PHP '.number_format((float) ($collections?->payment_total ?? 0), 2),
                                'reference' => null,
                                'customer' => null,
                                'address' => null,
                            ]
                            : [
                                'label' => 'Available for field assignment',
                                'detail' => 'No active assignment recorded in SolarNet.',
                                'reference' => null,
                                'customer' => null,
                                'address' => null,
                            ]);

                    return [
                    'user_id' => $location->user_id,
                    'name' => $location->user?->name,
                    'role' => $role,
                    'latitude' => $location->latitude,
                    'longitude' => $location->longitude,
                    'accuracy_meters' => $location->accuracy_meters,
                    'captured_at' => $location->captured_at?->toIso8601String(),
                    'activity' => $activity,
                    ];
                })->values();

        $data['staff_tracks'] = [];
        if (Schema::hasTable('staff_location_history')) {
            $history = StaffLocationHistory::query()
                ->where('captured_at', '>=', now()->subHours(12))
                ->where('captured_at', '<=', now()->addMinute())
                ->whereHas('user.roles', fn ($query) => $query->whereIn('name', ['collector', 'technician']))
                ->with('user.roles:id,name')
                ->orderBy('captured_at')
                ->get()
                ->groupBy('user_id');
            $staffById = $data['staff_locations']->keyBy('user_id');

            $data['staff_tracks'] = $history->map(function ($points, string $userId) use ($staffById): array {
                if ($points->count() > 240) {
                    $step = (int) ceil($points->count() / 240);
                    $points = $points->filter(fn ($point, int $index) => $index % $step === 0)
                        ->push($points->last())->unique('id')->values();
                }
                $staff = $staffById->get($userId);
                $firstPoint = $points->first();
                $role = $staff['role'] ?? $firstPoint?->user?->roles?->pluck('name')->intersect(['collector', 'technician'])->first();

                return [
                    'user_id' => $userId,
                    'name' => $staff['name'] ?? $firstPoint?->user?->name ?? 'Field staff',
                    'role' => $role ?? 'field_staff',
                    'points' => $points->map(fn (StaffLocationHistory $point): array => [
                        'latitude' => $point->latitude,
                        'longitude' => $point->longitude,
                        'accuracy_meters' => $point->accuracy_meters,
                        'captured_at' => $point->captured_at?->toIso8601String(),
                    ])->values()->all(),
                ];
            })->values();
        }

        return response()->json(['data' => $data]);
    }

    public function updateMyLocation(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole(['collector', 'technician']), 403);
        abort_unless(
            Schema::hasTable('staff_live_locations'),
            503,
            'Staff location storage is not installed yet. Run the pending database migrations.'
        );
        $workNow = now('Asia/Manila');
        abort_unless(
            $workNow->hour >= 6 && $workNow->hour < 18,
            422,
            'Work-location tracking accepts updates only from 6:00 AM to 6:00 PM Asia/Manila.'
        );
        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:5000'],
        ]);
        $capturedAt = now();
        $location = StaffLiveLocation::updateOrCreate(
            ['user_id' => $request->user()->id],
            [...$data, 'sharing_enabled' => true, 'captured_at' => $capturedAt]
        );
        if (Schema::hasTable('staff_location_history')) {
            StaffLocationHistory::create([...$data, 'user_id' => $request->user()->id, 'captured_at' => $capturedAt]);
            StaffLocationHistory::query()
                ->where('user_id', $request->user()->id)
                ->where('captured_at', '<', $capturedAt->copy()->subDays(30))
                ->delete();
        }

        return response()->json(['message' => 'Live location shared.', 'captured_at' => $location->captured_at]);
    }

    public function store(Request $request, OperationsMapService $operationsMap): JsonResponse
    {
        $asset = new OperationsMapAsset($this->validatedAsset($request));
        $asset->created_by = $request->user()->id;
        $asset->save();

        return response()->json([
            'message' => 'Map asset saved. This does not change RouterOS, OLT, or customer records.',
            'data' => $operationsMap->snapshot(),
        ], 201);
    }

    public function update(Request $request, OperationsMapAsset $operationsMapAsset, OperationsMapService $operationsMap): JsonResponse
    {
        $operationsMapAsset->fill($this->validatedAsset($request));
        $operationsMapAsset->save();

        return response()->json([
            'message' => 'Map asset updated. This does not change RouterOS, OLT, or customer records.',
            'data' => $operationsMap->snapshot(),
        ]);
    }

    public function destroy(OperationsMapAsset $operationsMapAsset, OperationsMapService $operationsMap): JsonResponse
    {
        $operationsMapAsset->delete();

        return response()->json([
            'message' => 'Map asset removed. Client, RouterOS, OLT, and billing records were not changed.',
            'data' => $operationsMap->snapshot(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validatedAsset(Request $request): array
    {
        $data = $request->validate([
            'asset_type' => ['required', Rule::in(['nap', 'pole', 'fiber_route'])],
            'name' => ['required', 'string', 'max:120'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'route_coordinates' => ['nullable', 'array', 'max:300'],
            'status' => ['nullable', Rule::in(['active', 'planned', 'retired'])],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        if ($data['asset_type'] === 'fiber_route') {
            $data['latitude'] = null;
            $data['longitude'] = null;
            $data['route_coordinates'] = $this->normalizeRouteCoordinates($data['route_coordinates'] ?? null);
        } else {
            $validator = Validator::make($data, [
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);
            if ($validator->fails()) throw new ValidationException($validator);
            $data['route_coordinates'] = null;
        }

        $data['status'] = $data['status'] ?? 'active';
        return $data;
    }

    /** @return array<int, array{latitude: float, longitude: float}> */
    private function normalizeRouteCoordinates(mixed $coordinates): array
    {
        if (!is_array($coordinates) || count($coordinates) < 2) {
            throw ValidationException::withMessages(['route_coordinates' => 'A fiber route needs at least two verified coordinate points.']);
        }

        $points = [];
        foreach ($coordinates as $index => $point) {
            if (!is_array($point) || !is_numeric($point['latitude'] ?? null) || !is_numeric($point['longitude'] ?? null)
                || (float) $point['latitude'] < -90 || (float) $point['latitude'] > 90
                || (float) $point['longitude'] < -180 || (float) $point['longitude'] > 180) {
                throw ValidationException::withMessages(["route_coordinates.{$index}" => 'Each fiber-route point needs a valid latitude and longitude.']);
            }
            $points[] = ['latitude' => (float) $point['latitude'], 'longitude' => (float) $point['longitude']];
        }

        return $points;
    }
}
