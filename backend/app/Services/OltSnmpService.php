<?php

namespace App\Services;

use App\Models\OltDevice;
use App\Models\Router;
use Illuminate\Support\Facades\Log;

/**
 * Read standard OLT SNMPv2-MIB values through a selected MikroTik router.
 *
 * OLT management addresses are commonly private. Polling through the existing
 * authenticated RouterOS API avoids exposing UDP/161 or adding a public NAT
 * rule. Only five fixed GET OIDs are relayed; no SNMP SET, WALK, ONU action,
 * router configuration, or OLT configuration path exists in this service.
 */
class OltSnmpService
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';
    private const SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';
    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';
    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';
    private const IF_NUMBER = '1.3.6.1.2.1.2.1.0';

    public function __construct(private readonly MikrotikService $mikrotik)
    {
    }

    public function inspect(OltDevice $olt): array
    {
        if ($olt->snmp_version !== '2c') {
            return $this->failure($olt, 'SNMP_VERSION_UNSUPPORTED', 'This safe OLT monitor supports SNMP v2c read-only polling only.');
        }

        if (blank($olt->snmp_community)) {
            return $this->failure($olt, 'SNMP_COMMUNITY_MISSING', 'Set a read-only SNMP community before testing this OLT.');
        }

        $router = $olt->relationLoaded('router') ? $olt->router : $olt->router()->first();
        if (!($router instanceof Router)) {
            return $this->failure($olt, 'RELAY_ROUTER_REQUIRED', 'Select the MikroTik management router that can reach this OLT. SolarNet will use its existing API connection; no public SNMP path is used.');
        }

        if (!$router->is_active) {
            return $this->failure($olt, 'RELAY_ROUTER_INACTIVE', "{$router->name} is inactive. Activate and verify its existing RouterOS API connection before testing this OLT.");
        }

        $first = $this->get($router, $olt, self::SYS_DESCR);
        if (!$first['success']) return $this->relayFailure($olt, $router, $first);

        $snapshot = [
            'system_description' => $first['value'],
            'system_object_id' => $this->valueOrNull($this->get($router, $olt, self::SYS_OBJECT_ID)),
            'system_uptime' => $this->valueOrNull($this->get($router, $olt, self::SYS_UPTIME)),
            'system_name' => $this->valueOrNull($this->get($router, $olt, self::SYS_NAME)),
            'interface_count' => $this->integerValue($this->valueOrNull($this->get($router, $olt, self::IF_NUMBER))),
            'polled_at' => now()->toIso8601String(),
            'mode' => 'read_only_standard_mib_via_mikrotik_api_relay',
            'relay_router' => $router->name,
        ];

        $olt->forceFill([
            'connection_status' => 'online',
            'last_checked_at' => now(),
            'last_snapshot' => $snapshot,
        ])->save();

        return [
            'success' => true,
            'message' => "Read-only OLT health check completed through {$router->name}. No OLT or RouterOS configuration was changed.",
            'data' => $snapshot,
        ];
    }

    private function get(Router $router, OltDevice $olt, string $oid): array
    {
        return $this->mikrotik->relaySnmpV2cGet($router, $olt->host, $olt->snmp_port, $olt->snmp_community, $oid);
    }

    private function relayFailure(OltDevice $olt, Router $router, array $result): array
    {
        $code = $result['code'] ?? 'RELAY_UNREACHABLE';
        $message = match ($code) {
            'RELAY_ROUTER_PERMISSION_MISSING' => "{$router->name}'s billing API account cannot run the read-only RouterOS SNMP tool. Add only the RouterOS test policy to that API account group, then retest. No OLT or RouterOS setting was changed.",
            'RELAY_NO_SNMP_RESPONSE' => "{$router->name} reached the OLT network but did not receive an SNMP response. Confirm UDP {$olt->snmp_port}, the read-only community, and the OLT ACL permits the router management address. No public SNMP rule is needed.",
            'RELAY_INPUT_INVALID' => 'The saved OLT relay target is invalid. Confirm the management IP and UDP port.',
            default => "Could not relay a read-only SNMP request through {$router->name}. Confirm the router API account and management-network path. No OLT or RouterOS configuration was changed.",
        };

        return $this->failure($olt, $code, $message, ['router_id' => $router->id, 'router_name' => $router->name]);
    }

    private function failure(OltDevice $olt, string $code, string $message, array $context = []): array
    {
        Log::warning('OLT SNMP health check failed', array_merge([
            'olt_id' => $olt->id,
            'host' => $olt->host,
            'port' => $olt->snmp_port,
            'code' => $code,
        ], $context));

        $olt->forceFill([
            'connection_status' => 'offline',
            'last_checked_at' => now(),
        ])->save();

        return ['success' => false, 'message' => $message, 'code' => $code];
    }

    private function valueOrNull(array $result): ?string
    {
        return ($result['success'] ?? false) && is_string($result['value'] ?? null)
            ? trim($result['value'])
            : null;
    }

    private function integerValue(?string $value): ?int
    {
        return is_string($value) && preg_match('/-?\d+/', $value, $matches)
            ? (int) $matches[0]
            : null;
    }
}
