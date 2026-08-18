<?php

namespace App\Services;

use App\Models\OltDevice;
use Illuminate\Support\Facades\Log;

/**
 * Generic, read-only SNMP monitoring for OLT management interfaces.
 *
 * This deliberately reads standard SNMPv2-MIB values only. ONU discovery,
 * provisioning, and reboot require an approved vendor MIB/API and are not
 * attempted here, so this service cannot alter an OLT configuration.
 */
class OltSnmpService
{
    private const SYS_DESCR = '1.3.6.1.2.1.1.1.0';
    private const SYS_OBJECT_ID = '1.3.6.1.2.1.1.2.0';
    private const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';
    private const SYS_NAME = '1.3.6.1.2.1.1.5.0';
    private const IF_NUMBER = '1.3.6.1.2.1.2.1.0';

    public function inspect(OltDevice $olt): array
    {
        if (!extension_loaded('snmp')) {
            return [
                'success' => false,
                'message' => 'SNMP support is not installed in the application container. Rebuild the backend image with the SolarNet SNMP update.',
                'code' => 'SNMP_EXTENSION_MISSING',
            ];
        }

        if ($olt->snmp_version !== '2c') {
            return [
                'success' => false,
                'message' => 'This first safe OLT monitor supports SNMP v2c read-only polling. SNMP v3 can be added after its OLT security profile is confirmed.',
                'code' => 'SNMP_VERSION_UNSUPPORTED',
            ];
        }

        if (blank($olt->snmp_community)) {
            return [
                'success' => false,
                'message' => 'Set a read-only SNMP community before testing this OLT.',
                'code' => 'SNMP_COMMUNITY_MISSING',
            ];
        }

        try {
            $client = new \SNMP(\SNMP::VERSION_2c, $this->endpoint($olt), $olt->snmp_community, 2_000_000, 0);
            $client->valueretrieval = \SNMP_VALUE_PLAIN;
            $client->quick_print = true;

            $snapshot = [
                'system_description' => $this->read($client, self::SYS_DESCR),
                'system_object_id' => $this->read($client, self::SYS_OBJECT_ID),
                'system_uptime' => $this->read($client, self::SYS_UPTIME),
                'system_name' => $this->read($client, self::SYS_NAME),
                'interface_count' => $this->integerValue($this->read($client, self::IF_NUMBER)),
                'polled_at' => now()->toIso8601String(),
                'mode' => 'read_only_standard_mib',
            ];
            $client->close();

            if (blank($snapshot['system_description']) && blank($snapshot['system_name'])) {
                throw new \RuntimeException('The OLT did not return standard SNMP system information.');
            }

            $olt->forceFill([
                'connection_status' => 'online',
                'last_checked_at' => now(),
                'last_snapshot' => $snapshot,
            ])->save();

            return [
                'success' => true,
                'message' => 'Read-only SNMP health check completed. No OLT setting was changed.',
                'data' => $snapshot,
            ];
        } catch (\Throwable $exception) {
            Log::warning('OLT SNMP health check failed', [
                'olt_id' => $olt->id,
                'host' => $olt->host,
                'port' => $olt->snmp_port,
                'exception' => $exception->getMessage(),
            ]);

            $olt->forceFill([
                'connection_status' => 'offline',
                'last_checked_at' => now(),
            ])->save();

            return [
                'success' => false,
                'message' => 'Could not read this OLT by SNMP. Confirm its management IP, UDP port, read-only community, and firewall ACL to the SolarNet server.',
                'code' => 'SNMP_UNREACHABLE',
            ];
        }
    }

    private function endpoint(OltDevice $olt): string
    {
        return filter_var($olt->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $olt->host . ']:' . $olt->snmp_port
            : $olt->host . ':' . $olt->snmp_port;
    }

    private function read(\SNMP $client, string $oid): ?string
    {
        $value = $client->get($oid);

        return is_string($value) && $value !== '' ? trim($value) : null;
    }

    private function integerValue(?string $value): ?int
    {
        return is_string($value) && preg_match('/-?\d+/', $value, $matches)
            ? (int) $matches[0]
            : null;
    }
}
