<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLocationEvent;
use App\Models\DhcpLease;
use App\Models\OperationsMapAsset;
use Illuminate\Support\Collection;

/**
 * Read-only operational map projection.
 *
 * The client indicator is derived from the latest synchronized RouterOS DHCP
 * lease. It does not claim an ICMP/Internet reachability test, and it never
 * contacts or modifies a router while a map is opened.
 */
class OperationsMapService
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $allCustomers = Customer::query()
            ->with('router:id,name')
            ->orderBy('full_name')
            ->get(['id', 'account_number', 'full_name', 'address', 'status', 'router_id', 'gps_coordinates'])
            ->values();

        // Some older location-capture flows wrote an auditable location event
        // before the customer profile's JSON field was updated. Use the latest
        // valid saved event only as a display fallback; do not rewrite any
        // customer record while an Operations Map is opened.
        $latestLocationEvents = CustomerLocationEvent::query()
            ->whereIn('customer_id', $allCustomers->pluck('id'))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->latest('created_at')
            ->get(['customer_id', 'source', 'latitude', 'longitude', 'created_at'])
            ->filter(fn (CustomerLocationEvent $event): bool => $this->hasCoordinates([
                'latitude' => $event->latitude,
                'longitude' => $event->longitude,
            ]))
            ->unique('customer_id')
            ->keyBy('customer_id');

        $customers = $allCustomers
            ->filter(fn (Customer $customer): bool => $this->coordinatesFor($customer, $latestLocationEvents->get($customer->id)) !== null)
            ->values();

        $leasesByCustomer = DhcpLease::query()
            ->whereIn('customer_id', $customers->pluck('id'))
            ->presentOnRouter()
            ->with('router:id,name')
            ->orderByDesc('last_seen_at')
            ->get(['id', 'customer_id', 'router_id', 'ip_address', 'status', 'last_seen_at', 'is_current'])
            ->groupBy('customer_id');

        $clients = $customers->map(function (Customer $customer) use ($leasesByCustomer, $latestLocationEvents): array {
            /** @var Collection<int, DhcpLease> $customerLeases */
            $customerLeases = $leasesByCustomer->get($customer->id, collect());
            $boundLease = $customerLeases->first(fn (DhcpLease $lease): bool => strtolower((string) $lease->status) === 'bound');
            $lease = $boundLease ?: $customerLeases->first();
            $state = $this->networkState((string) $customer->status, $boundLease !== null);
            $coordinates = $this->coordinatesFor($customer, $latestLocationEvents->get($customer->id));

            return [
                'id' => $customer->id,
                'account_number' => $customer->account_number,
                'full_name' => $customer->full_name,
                'address' => $customer->address,
                'customer_status' => $customer->status,
                'latitude' => (float) $coordinates['latitude'],
                'longitude' => (float) $coordinates['longitude'],
                'location_source' => $coordinates['source'],
                'network_state' => $state,
                'network_label' => match ($state) {
                    'online' => 'Live DHCP lease',
                    'offline' => 'No current DHCP lease',
                    'restricted' => 'Billing restricted',
                    default => 'Network state unavailable',
                },
                'lease' => $lease ? [
                    'ip_address' => $lease->ip_address,
                    'status' => $lease->status,
                    'last_seen_at' => $lease->last_seen_at?->toIso8601String(),
                    'router_name' => $lease->router?->name ?? $customer->router?->name,
                ] : null,
            ];
        })->values();

        $assets = OperationsMapAsset::query()
            ->with('createdBy:id,name')
            ->orderBy('asset_type')
            ->orderBy('name')
            ->get()
            ->map(fn (OperationsMapAsset $asset) => $asset->toMapArray())
            ->values();

        $counts = [
            'online' => $clients->where('network_state', 'online')->count(),
            'offline' => $clients->where('network_state', 'offline')->count(),
            'restricted' => $clients->where('network_state', 'restricted')->count(),
            'unknown' => $clients->where('network_state', 'unknown')->count(),
        ];

        return [
            'clients' => $clients,
            'assets' => $assets,
            'summary' => [
                'mapped_clients' => $clients->count(),
                'unmapped_clients' => max(0, $allCustomers->count() - $clients->count()),
                'network_states' => $counts,
                'assets' => [
                    'naps' => $assets->where('asset_type', 'nap')->count(),
                    'poles' => $assets->where('asset_type', 'pole')->count(),
                    'fiber_routes' => $assets->where('asset_type', 'fiber_route')->count(),
                ],
            ],
            'source_note' => 'Client network indicators use the latest synchronized RouterOS DHCP lease. A live lease is not a speed test or an end-to-end Internet probe.',
            'generated_at' => now(config('app.timezone', 'Asia/Manila'))->toIso8601String(),
        ];
    }

    private function hasCoordinates(mixed $coordinates): bool
    {
        return is_array($coordinates)
            && is_numeric($coordinates['latitude'] ?? null)
            && is_numeric($coordinates['longitude'] ?? null)
            && (float) $coordinates['latitude'] >= -90
            && (float) $coordinates['latitude'] <= 90
            && (float) $coordinates['longitude'] >= -180
            && (float) $coordinates['longitude'] <= 180;
    }

    /** @return array{latitude: float, longitude: float, source: string}|null */
    private function coordinatesFor(Customer $customer, ?CustomerLocationEvent $latestEvent): ?array
    {
        if ($this->hasCoordinates($customer->gps_coordinates)) {
            return [
                'latitude' => (float) $customer->gps_coordinates['latitude'],
                'longitude' => (float) $customer->gps_coordinates['longitude'],
                'source' => $customer->location_source ?: 'customer_record',
            ];
        }

        if ($latestEvent && $this->hasCoordinates(['latitude' => $latestEvent->latitude, 'longitude' => $latestEvent->longitude])) {
            return [
                'latitude' => (float) $latestEvent->latitude,
                'longitude' => (float) $latestEvent->longitude,
                'source' => 'location_event:' . ($latestEvent->source ?: 'saved_capture'),
            ];
        }

        return null;
    }

    private function networkState(string $customerStatus, bool $hasBoundLease): string
    {
        $status = strtolower($customerStatus);
        if (in_array($status, ['suspended', 'expired', 'disconnected'], true)) return 'restricted';
        if ($status !== 'active') return 'unknown';
        return $hasBoundLease ? 'online' : 'offline';
    }
}
