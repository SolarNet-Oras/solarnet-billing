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
     * An IP is used only to bind this browser session to an already matched
     * DHCP lease. The authenticated customer, router, lease and stored ONU
     * reference must all agree; ambiguity always fails closed.
     */
    public function createRequest(Customer $customer, string $sourceIp): array
    {
        if (blank($customer->onu_information) || !$customer->router_id) {
            return ['eligible' => false, 'reason' => 'SolarNet could not safely identify your service connection. Please contact support.'];
        }

        $leases = DhcpLease::query()
            ->where('customer_id', $customer->id)
            ->where('router_id', $customer->router_id)
            ->where('ip_address', $sourceIp)
            ->where('is_matched', true)
            ->where('is_current', true)
            ->where('status', 'bound')
            ->get();

        if ($leases->count() !== 1) {
            return ['eligible' => false, 'reason' => 'SolarNet could not safely identify your service connection. Please contact support.'];
        }

        $token = Str::random(64);
        $request = DB::transaction(function () use ($customer, $sourceIp, $leases, $token) {
            CustomerLocationCaptureRequest::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', ['pending', 'captured'])
                ->update(['status' => 'expired', 'expired_at' => now()]);

            $capture = CustomerLocationCaptureRequest::create([
                'customer_id' => $customer->id,
                'router_id' => $customer->router_id,
                'dhcp_lease_id' => $leases->first()->id,
                'onu_reference' => trim((string) $customer->onu_information),
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

        $threshold = (float) config('services.location_capture.max_accuracy_meters', 50);
        if ($accuracy > $threshold) return ['success' => false, 'message' => "Your current location accuracy is approximately {$accuracy} meters. Please move closer to your Internet installation location and try again.", 'status' => 422];

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
        if (!$capture || $capture->router_id !== $customer->router_id || $capture->onu_reference !== trim((string) $customer->onu_information)) return null;
        $lease = DhcpLease::query()->whereKey($capture->dhcp_lease_id)->where('customer_id', $customer->id)->where('router_id', $customer->router_id)->where('ip_address', $sourceIp)->where('is_matched', true)->where('is_current', true)->where('status', 'bound')->first();
        return $lease ? $capture : null;
    }

    private function event(Customer $customer, CustomerLocationCaptureRequest $capture, string $action, ?float $latitude = null, ?float $longitude = null, ?float $accuracy = null): void
    {
        CustomerLocationEvent::create(['customer_id' => $customer->id, 'location_capture_request_id' => $capture->id, 'onu_reference' => $capture->onu_reference, 'source' => 'customer_device', 'action' => $action, 'latitude' => $latitude, 'longitude' => $longitude, 'accuracy_meters' => $accuracy]);
    }
}
