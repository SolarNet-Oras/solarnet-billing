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
 * rule. Only fixed standard-health GET OIDs, a small HSGQ non-sensitive
 * device-health allowlist, and bounded standard IF-MIB table columns are
 * relayed; no SNMP SET, vendor-tree walk, ONU action, router configuration,
 * or OLT configuration path exists in this service.
 */
class OltSnmpService
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';
    private const SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';
    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';
    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';
    private const IF_NUMBER = '1.3.6.1.2.1.2.1.0';

    /**
     * HSGQ-G04R vendor values observed from the OLT's private enterprise
     * subtree. These are deliberately limited to non-sensitive, human-readable
     * device-identification and health strings. No ONU tables, account tables,
     * configuration values, or unlabelled counters are queried or stored.
     */
    private const HSGQ_ENTERPRISE_ROOT = '1.3.6.1.4.1.50224.';
    private const HSGQ_SAFE_HEALTH_OIDS = [
        'platform_version' => '1.3.6.1.4.1.50224.3.1.1.5.0',
        'firmware_release' => '1.3.6.1.4.1.50224.3.1.1.6.0',
        'software_version' => '1.3.6.1.4.1.50224.3.1.1.7.0',
        'model' => '1.3.6.1.4.1.50224.3.1.1.19.0',
        'build' => '1.3.6.1.4.1.50224.3.1.1.20.0',
        'fan_reading' => '1.3.6.1.4.1.50224.3.1.1.21.0',
        'power_source' => '1.3.6.1.4.1.50224.3.1.1.24.0',
    ];

    /**
     * Standard IF-MIB columns only. These contain operational interface
     * telemetry, not OLT configuration, user, ONU, or SNMP security data.
     */
    private const IF_MIB_COLUMNS = [
        'name' => '1.3.6.1.2.1.31.1.1.1.1',
        'admin_status' => '1.3.6.1.2.1.2.2.1.7',
        'oper_status' => '1.3.6.1.2.1.2.2.1.8',
        'speed_mbps' => '1.3.6.1.2.1.31.1.1.1.15',
        'in_octets' => '1.3.6.1.2.1.31.1.1.1.6',
        'out_octets' => '1.3.6.1.2.1.31.1.1.1.10',
        'in_errors' => '1.3.6.1.2.1.2.2.1.14',
        'out_errors' => '1.3.6.1.2.1.2.2.1.20',
        'in_discards' => '1.3.6.1.2.1.2.2.1.13',
        'out_discards' => '1.3.6.1.2.1.2.2.1.19',
    ];

    private const IF_MIB_DESCRIPTION_FALLBACK = '1.3.6.1.2.1.2.2.1.2';

    private const MAX_INTERFACE_ROWS = 512;

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

        $systemObjectId = $this->valueOrNull($this->get($router, $olt, self::SYS_OBJECT_ID));

        $snapshot = array_merge(is_array($olt->last_snapshot) ? $olt->last_snapshot : [], [
            'system_description' => $first['value'],
            'system_object_id' => $systemObjectId,
            'system_uptime' => $this->valueOrNull($this->get($router, $olt, self::SYS_UPTIME)),
            'system_name' => $this->valueOrNull($this->get($router, $olt, self::SYS_NAME)),
            'interface_count' => $this->integerValue($this->valueOrNull($this->get($router, $olt, self::IF_NUMBER))),
            'hsgq_vendor_health' => $this->hsgqVendorHealth($router, $olt, $systemObjectId),
            'polled_at' => now()->toIso8601String(),
            'mode' => 'read_only_standard_mib_via_mikrotik_api_relay',
            'relay_router' => $router->name,
        ]);

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

    /**
     * Read a bounded, standard IF-MIB port snapshot through the selected
     * MikroTik. This is on-demand only; it never starts background polling and
     * never queries vendor account/configuration/ONU tables.
     */
    public function refreshInterfaceMonitoring(OltDevice $olt): array
    {
        $context = $this->relayContext($olt);
        if ($context['failure'] ?? false) return $context['result'];

        /** @var Router $router */
        $router = $context['router'];
        $columns = [];
        $truncated = false;

        $names = $this->mikrotik->relaySnmpV2cWalk(
            $router,
            $olt->host,
            $olt->snmp_port,
            $olt->snmp_community,
            self::IF_MIB_COLUMNS['name'],
            self::MAX_INTERFACE_ROWS,
        );

        // Older SNMP agents may not implement the IF-MIB ifName extension.
        // ifDescr is still standard, safe operational telemetry and provides a
        // human-readable interface label without touching vendor tables.
        if (!($names['success'] ?? false)) {
            $names = $this->mikrotik->relaySnmpV2cWalk(
                $router,
                $olt->host,
                $olt->snmp_port,
                $olt->snmp_community,
                self::IF_MIB_DESCRIPTION_FALLBACK,
                self::MAX_INTERFACE_ROWS,
            );
        }

        if (!($names['success'] ?? false)) return $this->relayFailure($olt, $router, $names);

        $columns['name'] = $this->rowsByIndex($names['rows'] ?? []);
        $truncated = (bool) ($names['truncated'] ?? false);

        foreach (self::IF_MIB_COLUMNS as $key => $oid) {
            if ($key === 'name') continue;

            $result = $this->mikrotik->relaySnmpV2cWalk(
                $router,
                $olt->host,
                $olt->snmp_port,
                $olt->snmp_community,
                $oid,
                self::MAX_INTERFACE_ROWS,
            );

            if (!($result['success'] ?? false)) {
                continue;
            }

            $columns[$key] = $this->rowsByIndex($result['rows'] ?? []);
            $truncated = $truncated || (bool) ($result['truncated'] ?? false);
        }

        $names = $columns['name'] ?? [];
        if ($names === []) {
            return $this->failure($olt, 'RELAY_NO_SNMP_RESPONSE', 'The OLT did not return standard IF-MIB interface names. No OLT or RouterOS configuration was changed.', ['router_id' => $router->id, 'router_name' => $router->name]);
        }

        $sampledAt = now();
        $previous = data_get($olt->last_snapshot, 'interface_monitoring');
        $previousInterfaces = $this->previousInterfaces($previous);
        $previousAt = is_array($previous) && is_string($previous['sampled_at'] ?? null)
            ? $this->parseSnapshotTime($previous['sampled_at'])
            : null;
        $elapsedSeconds = $previousAt ? max(1, $sampledAt->diffInSeconds($previousAt)) : null;

        $interfaces = [];
        foreach ($names as $index => $name) {
            $currentIn = $columns['in_octets'][$index] ?? null;
            $currentOut = $columns['out_octets'][$index] ?? null;
            $prior = $previousInterfaces[$index] ?? null;

            $interfaces[] = [
                'index' => $index,
                'name' => filled($name) ? $name : "ifIndex {$index}",
                'admin_status' => $this->interfaceStatus($columns['admin_status'][$index] ?? null),
                'oper_status' => $this->interfaceStatus($columns['oper_status'][$index] ?? null),
                'speed_mbps' => $this->integerValue($columns['speed_mbps'][$index] ?? null),
                'in_octets' => $currentIn,
                'out_octets' => $currentOut,
                'rx_bytes_per_second' => $this->counterRate($currentIn, $prior['in_octets'] ?? null, $elapsedSeconds),
                'tx_bytes_per_second' => $this->counterRate($currentOut, $prior['out_octets'] ?? null, $elapsedSeconds),
                'in_errors' => $this->integerValue($columns['in_errors'][$index] ?? null),
                'out_errors' => $this->integerValue($columns['out_errors'][$index] ?? null),
                'in_discards' => $this->integerValue($columns['in_discards'][$index] ?? null),
                'out_discards' => $this->integerValue($columns['out_discards'][$index] ?? null),
            ];
        }

        $monitoring = [
            'sampled_at' => $sampledAt->toIso8601String(),
            'interface_count' => count($interfaces),
            'interfaces' => $interfaces,
            'truncated' => $truncated,
            'mode' => 'read_only_standard_if_mib_via_mikrotik_api_relay',
            'relay_router' => $router->name,
        ];

        $snapshot = array_merge(is_array($olt->last_snapshot) ? $olt->last_snapshot : [], [
            'interface_monitoring' => $monitoring,
        ]);

        $olt->forceFill([
            'connection_status' => 'online',
            'last_checked_at' => $sampledAt,
            'last_snapshot' => $snapshot,
        ])->save();

        return [
            'success' => true,
            'message' => "Read-only IF-MIB interface snapshot completed through {$router->name}. No OLT or RouterOS configuration was changed.",
            'data' => $monitoring,
        ];
    }

    private function get(Router $router, OltDevice $olt, string $oid): array
    {
        return $this->mikrotik->relaySnmpV2cGet($router, $olt->host, $olt->snmp_port, $olt->snmp_community, $oid);
    }

    /** @return array{failure: bool, result?: array<string, mixed>, router?: Router} */
    private function relayContext(OltDevice $olt): array
    {
        if ($olt->snmp_version !== '2c') {
            return ['failure' => true, 'result' => $this->failure($olt, 'SNMP_VERSION_UNSUPPORTED', 'This safe OLT monitor supports SNMP v2c read-only polling only.')];
        }

        if (blank($olt->snmp_community)) {
            return ['failure' => true, 'result' => $this->failure($olt, 'SNMP_COMMUNITY_MISSING', 'Set a read-only SNMP community before testing this OLT.')];
        }

        $router = $olt->relationLoaded('router') ? $olt->router : $olt->router()->first();
        if (!($router instanceof Router)) {
            return ['failure' => true, 'result' => $this->failure($olt, 'RELAY_ROUTER_REQUIRED', 'Select the MikroTik management router that can reach this OLT. SolarNet will use its existing API connection; no public SNMP path is used.')];
        }

        if (!$router->is_active) {
            return ['failure' => true, 'result' => $this->failure($olt, 'RELAY_ROUTER_INACTIVE', "{$router->name} is inactive. Activate and verify its existing RouterOS API connection before testing this OLT.")];
        }

        return ['failure' => false, 'router' => $router];
    }

    /**
     * @return array<string, string>|null
     */
    private function hsgqVendorHealth(Router $router, OltDevice $olt, ?string $systemObjectId): ?array
    {
        if (!is_string($systemObjectId) || !str_starts_with(ltrim($systemObjectId, '.'), self::HSGQ_ENTERPRISE_ROOT)) {
            return null;
        }

        $health = [];
        foreach (self::HSGQ_SAFE_HEALTH_OIDS as $key => $oid) {
            $value = $this->valueOrNull($this->get($router, $olt, $oid));
            if ($value !== null) $health[$key] = $value;
        }

        return $health ?: null;
    }

    /** @param array<int, array{index?: int, value?: string}> $rows @return array<int, string> */
    private function rowsByIndex(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $index = $row['index'] ?? null;
            $value = $row['value'] ?? null;
            if (is_int($index) && is_string($value)) $values[$index] = $value;
        }

        ksort($values, SORT_NUMERIC);

        return $values;
    }

    /** @return array<int, array<string, mixed>> */
    private function previousInterfaces(mixed $monitoring): array
    {
        if (!is_array($monitoring) || !is_array($monitoring['interfaces'] ?? null)) return [];

        $interfaces = [];
        foreach ($monitoring['interfaces'] as $interface) {
            if (is_array($interface) && is_int($interface['index'] ?? null)) $interfaces[$interface['index']] = $interface;
        }

        return $interfaces;
    }

    private function parseSnapshotTime(string $value): ?\Carbon\CarbonInterface
    {
        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function interfaceStatus(?string $value): string
    {
        return match ($this->integerValue($value)) {
            1 => 'up',
            2 => 'down',
            3 => 'testing',
            4 => 'unknown',
            5 => 'dormant',
            6 => 'not_present',
            7 => 'lower_layer_down',
            default => 'not_reported',
        };
    }

    private function counterRate(?string $current, mixed $previous, ?int $elapsedSeconds): ?float
    {
        if ($elapsedSeconds === null || !is_string($current) || !is_scalar($previous)) return null;

        $current = trim($current);
        $previous = trim((string) $previous);
        if (!preg_match('/^\d+$/', $current) || !preg_match('/^\d+$/', $previous)) return null;

        $delta = (float) $current - (float) $previous;

        return $delta >= 0 ? round($delta / $elapsedSeconds, 2) : null;
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
