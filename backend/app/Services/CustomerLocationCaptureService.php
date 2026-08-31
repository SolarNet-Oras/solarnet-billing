<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerLocationCaptureRequest;
use App\Models\CustomerLocationEvent;
use App\Models\DhcpLease;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Consent-based, verified installation location capture and refresh. */
class CustomerLocationCaptureService
{
    public const TOKEN_TTL_MINUTES = 30;

    /**
     * The source IP binds the three browser requests to one short-lived flow.
     * It is not compared with a private DHCP address: HTTPS sees the public
     * NAT address while RouterOS stores the subscriber's private lease. The
     * The authenticated customer and, when present, exactly one current
     * matched lease must agree. The saved ONU reference is preferred, then an
     * exact lease MAC. A migrated customer with no device record is bound to
     * the signed account ID. Multiple device candidates always fail closed.
     */
    public function createRequest(Customer $customer, string $sourceIp): array
    {
        $leaseQuery = DhcpLease::query()
            ->where('customer_id', $customer->id)
            ->where('is_matched', true)
            ->where('is_current', true)
            ->where('status', 'bound');
        if ($customer->router_id) $leaseQuery->where('router_id', $customer->router_id);

        $leases = $leaseQuery->get();
        $registeredMac = $this->normalizeMac($customer->mac_address);
        if ($registeredMac !== null) {
            $exactMac = $leases->filter(fn (DhcpLease $lease) => $this->normalizeMac($lease->mac_address) === $registeredMac)->values();
            if ($exactMac->count() === 1) $leases = $exactMac;
        }

        if ($leases->count() > 1) {
            return ['eligible' => false, 'reason' => 'More than one current device is linked to this customer. SolarNet support must review the device binding before location can be saved.'];
        }

        $lease = $leases->first();
        $onuReference = $this->identityReference($customer, $lease);
        $token = Str::random(64);
        $request = DB::transaction(function () use ($customer, $sourceIp, $lease, $onuReference, $token) {
            CustomerLocationCaptureRequest::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['pending', 'captured'])
                ->update(['status' => 'expired', 'expired_at' => now()]);

            $capture = CustomerLocationCaptureRequest::create([
                'customer_id' => $customer->id,
                'router_id' => $lease?->router_id ?? $customer->router_id,
                'dhcp_lease_id' => $lease?->id,
                'onu_reference' => $onuReference,
                'token_hash' => hash('sha256', $token),
                'source_ip' => $sourceIp,
                'status' => 'pending',
                'requested_at' => now(),
                'shown_at' => now(),
                'expired_at' => now()->addMinutes(self::TOKEN_TTL_MINUTES),
            ]);
            $customer->forceFill(['location_status' => 'pending'])->save();
            $this->event($customer, $capture, 'requested');
            return $capture;
        });

        return [
            'eligible' => true,
            'token' => $token,
            'expires_at' => $request->expired_at?->toIso8601String(),
            'onu_reference' => $request->onu_reference,
        ];
    }

    public function capture(Customer $customer, string $token, string $sourceIp, float $latitude, float $longitude, float $accuracy): array
    {
        $capture = $this->validRequest($customer, $token, $sourceIp);
        if (!$capture) return ['success' => false, 'message' => 'This location request is invalid, expired, or cannot be safely associated with your service.'];

        // Browser accuracy is an uncertainty radius reported by GPS; it is not
        // the phone's distance from the ONU/router. Normal phones commonly
        // report 10-30 m even beside the installed equipment, so never enforce
        // a production override stricter than the safe 50 m acceptance floor.
        $threshold = max(50.0, (float) config('services.location_capture.max_accuracy_meters', 50));
        if ($accuracy > $threshold) return ['success' => false, 'message' => "Your phone reported location accuracy of approximately ".round($accuracy, 1)." meters. Turn on Precise Location/High Accuracy and try near a window or open area. Your distance from the router does not affect GPS accuracy.", 'status' => 422];

        $capture->forceFill([
            'accepted_at' => $capture->accepted_at ?? now(),
            'status' => 'captured',
            'latitude' => $latitude, 'longitude' => $longitude, 'accuracy_meters' => $accuracy,
            'captured_at' => now(),
        ])->save();
        $customer->forceFill(['location_status' => 'captured'])->save();
        $this->event($customer, $capture, 'captured', $latitude, $longitude, $accuracy);

        return ['success' => true, 'latitude' => $latitude, 'longitude' => $longitude, 'accuracy' => $accuracy];
    }

    public function confirm(Customer $customer, string $token, string $sourceIp): array
    {
        return DB::transaction(function () use ($customer, $token, $sourceIp) {
            $capture = $this->validRequest($customer, $token, $sourceIp, true);
            if (!$capture || $capture->latitude === null || $capture->longitude === null || $capture->accuracy_meters === null) {
                return ['success' => false, 'message' => 'This location request is incomplete, expired, or cannot be safely associated with your service.'];
            }
            $capture->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
            $customer->forceFill([
                'gps_coordinates' => ['latitude' => (float) $capture->latitude, 'longitude' => (float) $capture->longitude],
                'location_status' => 'confirmed', 'location_source' => 'customer_device',
                'location_accuracy_meters' => $capture->accuracy_meters,
                'location_captured_at' => $capture->captured_at, 'location_confirmed_at' => now(),
            ])->save();
            $this->event($customer, $capture, 'confirmed', (float) $capture->latitude, (float) $capture->longitude, (float) $capture->accuracy_meters);
            return ['success' => true, 'customer' => $customer->fresh()];
        });
    }

    private function validRequest(Customer $customer, string $token, string $sourceIp, bool $lock = false): ?CustomerLocationCaptureRequest
    {
        $query = CustomerLocationCaptureRequest::query()->where('customer_id', $customer->id)->where('token_hash', hash('sha256', $token))->whereIn('status', ['pending', 'captured'])->where('source_ip', $sourceIp)->where('expired_at', '>', now());
        if ($lock) $query->lockForUpdate();
        $capture = $query->first();
        if (!$capture) return null;
        if ($capture->dhcp_lease_id) {
            $lease = DhcpLease::query()->whereKey($capture->dhcp_lease_id)->where('customer_id', $customer->id)->where('router_id', $capture->router_id)->where('is_matched', true)->where('is_current', true)->where('status', 'bound')->first();
            if (!$lease || $capture->onu_reference !== $this->identityReference($customer, $lease)) return null;
        } elseif ($capture->onu_reference !== $this->identityReference($customer, null)) {
            return null;
        }
        return $capture;
    }

    private function identityReference(Customer $customer, ?DhcpLease $lease): string
    {
        $onu = trim((string) $customer->onu_information);
        if ($onu !== '') return 'ONU:'.$onu;
        if ($lease) return 'MAC:'.($this->normalizeMac($lease->mac_address) ?? strtoupper((string) $lease->mac_address));
        return 'ACCOUNT:'.$customer->id;
    }

    private function normalizeMac(?string $value): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $value) ?? '');
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : null;
    }

    private function event(Customer $customer, CustomerLocationCaptureRequest $capture, string $action, ?float $latitude = null, ?float $longitude = null, ?float $accuracy = null): void
    {
        CustomerLocationEvent::create(['customer_id' => $customer->id, 'location_capture_request_id' => $capture->id, 'onu_reference' => $capture->onu_reference, 'source' => 'customer_device', 'action' => $action, 'latitude' => $latitude, 'longitude' => $longitude, 'accuracy_meters' => $accuracy]);
    }
}
