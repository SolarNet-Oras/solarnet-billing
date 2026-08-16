<?php

namespace App\Services;

/**
 * Pure, conservative plan builder for Internal DNS Branding.
 *
 * It intentionally has no RouterOS client. Planning must never make a router
 * change, and all record ownership decisions are made from a fresh discovery.
 */
class RouterDnsBrandingPlanner
{
    public const OWNER_PREFIX = 'SolarNet-DNS:v1';

    public function build(array $discovery, array $input): array
    {
        $domain = $this->normalizeDomain((string) ($input['domain'] ?? ''));
        if ($domain === null) {
            return ['success' => false, 'message' => 'Use a valid internal DNS domain, such as solarnet.local or lan.solarnetconnection.com.'];
        }

        $errors = [];
        $warnings = [];
        if (str_ends_with($domain, '.local')) {
            $warnings[] = '.local is supported as requested, but some Apple and Android devices use it for mDNS. A private subdomain you control, such as lan.solarnetconnection.com, avoids that naming conflict.';
        }

        $staticRecords = is_array($discovery['static_records'] ?? null) ? $discovery['static_records'] : [];
        $ownedById = [];
        $recordsByName = [];
        foreach ($staticRecords as $record) {
            $id = (string) ($record['id'] ?? '');
            $name = $this->canonicalHostname((string) ($record['name'] ?? ''));
            if ($id !== '' && ($record['owned_by_solarnet'] ?? false)) $ownedById[$id] = $record;
            if ($name !== '') $recordsByName[$name][] = $record;
        }

        $requested = is_array($input['records'] ?? null) ? $input['records'] : [];
        $plannedRecords = [];
        $seenNames = [];
        foreach ($requested as $index => $record) {
            if (!is_array($record)) {
                $errors[] = 'Each DNS record must contain a hostname, type, and address.';
                continue;
            }

            $rawHostname = trim((string) ($record['hostname'] ?? ''));
            $address = trim((string) ($record['address'] ?? ''));
            // Empty rows are allowed in the form but never reach RouterOS.
            if ($rawHostname === '' && $address === '') continue;

            $hostname = $this->hostnameForDomain($rawHostname, $domain);
            $type = strtoupper(trim((string) ($record['type'] ?? 'A')));
            $ttl = (int) ($record['ttl'] ?? 86400);
            if ($hostname === null) $errors[] = 'Record ' . ($index + 1) . ' must use a valid hostname inside ' . $domain . '.';
            if (!in_array($type, ['A', 'AAAA'], true)) $errors[] = 'Record ' . ($index + 1) . ' must use A or AAAA. CNAME is intentionally not enabled in this first safe release.';
            if (!$this->validAddress($address, $type)) $errors[] = 'Record ' . ($index + 1) . " has an invalid {$type} address.";
            if ($ttl < 60 || $ttl > 604800) $errors[] = 'Record ' . ($index + 1) . ' TTL must be between 60 and 604800 seconds.';
            if ($hostname === null || !in_array($type, ['A', 'AAAA'], true) || !$this->validAddress($address, $type) || $ttl < 60 || $ttl > 604800) continue;
            if (isset($seenNames[$hostname])) {
                $errors[] = "Duplicate requested hostname: {$hostname}.";
                continue;
            }
            $seenNames[$hostname] = true;

            $existing = $recordsByName[$hostname] ?? [];
            if (count($existing) > 1) {
                $errors[] = "{$hostname} has multiple existing RouterOS DNS entries. SolarNet will not guess which one to change.";
                continue;
            }
            $unknown = array_values(array_filter($existing, fn (array $item) => !($item['owned_by_solarnet'] ?? false)));
            if ($unknown !== []) {
                $errors[] = "{$hostname} is an existing protected DNS record. SolarNet will not overwrite or remove it.";
                continue;
            }
            $owned = $existing[0] ?? null;
            $unchanged = $owned
                && strtoupper((string) ($owned['type'] ?? 'A')) === $type
                && (string) ($owned['address'] ?? '') === $address
                && $this->ttlSeconds((string) ($owned['ttl'] ?? '')) === $ttl;

            $plannedRecords[] = [
                'action' => $owned ? ($unchanged ? 'unchanged' : 'replace_solarnet') : 'add_solarnet',
                'existing_id' => $owned['id'] ?? null,
                'previous' => $owned,
                'hostname' => $hostname,
                'short_hostname' => $this->shortHostname($hostname, $domain),
                'type' => $type,
                'address' => $address,
                'ttl_seconds' => $ttl,
                'description' => trim((string) ($record['description'] ?? '')),
            ];
        }

        if ($plannedRecords === []) $errors[] = 'Add at least one complete A or AAAA internal record before previewing.';

        $removeIds = array_values(array_unique(array_filter(array_map('strval', (array) ($input['remove_record_ids'] ?? [])))));
        $removals = [];
        foreach ($removeIds as $id) {
            $existing = $ownedById[$id] ?? null;
            if (!$existing) {
                $errors[] = 'Only an existing SolarNet-owned record can be removed.';
                continue;
            }
            if (isset($seenNames[$this->canonicalHostname((string) ($existing['name'] ?? ''))])) {
                $errors[] = 'A record cannot be removed and replaced in the same DNS plan.';
                continue;
            }
            $removals[] = ['action' => 'remove_solarnet', 'existing_id' => $id, 'previous' => $existing];
        }

        $approvedNetworkIds = array_values(array_unique(array_filter(array_map('strval', (array) ($input['approved_dhcp_network_ids'] ?? [])))));
        $networksById = [];
        foreach ((array) ($discovery['dhcp_networks'] ?? []) as $network) {
            if (!empty($network['id'])) $networksById[(string) $network['id']] = $network;
        }
        $dhcpChanges = [];
        foreach ($approvedNetworkIds as $id) {
            $network = $networksById[$id] ?? null;
            if (!$network || !($network['manageable'] ?? false)) {
                $errors[] = 'An approved DHCP network is missing, disabled, or is not a managed customer gateway. It was not included.';
                continue;
            }
            if (empty($network['gateway'])) {
                $errors[] = 'A DHCP network without a known gateway cannot be changed safely.';
                continue;
            }
            $dhcpChanges[] = [
                'network_id' => $id,
                'server_name' => $network['server_name'] ?? null,
                'interface' => $network['interface'] ?? null,
                'network' => $network['network'] ?? null,
                'gateway' => $network['gateway'],
                'previous_dns_server' => $network['dns_server'] ?? '',
                'new_dns_server' => $network['gateway'],
            ];
        }

        if ($dhcpChanges !== [] && !($discovery['allow_remote_requests'] ?? false)) {
            $errors[] = 'RouterOS DNS remote requests are currently disabled. SolarNet will not enable a resolver that could be reachable from WAN. Leave DHCP unchanged and configure a restricted LAN/VLAN DNS firewall policy through your normal change process, then enable remote requests manually and rescan.';
        }
        if ($dhcpChanges === []) {
            $warnings[] = 'No DHCP network was explicitly approved. Static records can be added, but this workflow will not distribute the router DNS server to customers.';
        }
        if (($discovery['upstream_dns_available'] ?? false) !== true) {
            $errors[] = 'The router has no detected upstream DNS server or DoH resolver. SolarNet will not point DHCP clients at a resolver that cannot reach external DNS.';
        }

        if ($errors !== []) return ['success' => false, 'message' => implode(' ', $errors), 'errors' => $errors, 'warnings' => $warnings];

        $changed = array_values(array_filter($plannedRecords, fn (array $record) => $record['action'] !== 'unchanged'));
        return ['success' => true, 'data' => [
            'kind' => 'solarnet_internal_dns_v1',
            'domain' => $domain,
            'input' => [
                'domain' => $domain,
                'records' => $requested,
                'approved_dhcp_network_ids' => $approvedNetworkIds,
                'remove_record_ids' => $removeIds,
            ],
            'records' => $plannedRecords,
            'record_changes' => $changed,
            'record_removals' => $removals,
            'dhcp_changes' => $dhcpChanges,
            'warnings' => $warnings,
            'protected' => [
                'unknown_static_records' => count(array_filter($staticRecords, fn (array $record) => !($record['owned_by_solarnet'] ?? false))),
                'router_dns_configuration_changed' => false,
                'wan_changed' => false,
                'public_ip_changed' => false,
                'nat_changed' => false,
                'routing_changed' => false,
                'firewall_changed' => false,
                'vlan_changed' => false,
                'qos_changed' => false,
                'billing_changed' => false,
            ],
        ]];
    }

    private function normalizeDomain(string $domain): ?string
    {
        $domain = strtolower(rtrim(trim($domain), '.'));
        if (strlen($domain) < 3 || strlen($domain) > 253 || !str_contains($domain, '.')) return null;
        foreach (explode('.', $domain) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label) !== 1) return null;
        }
        return $domain;
    }

    private function hostnameForDomain(string $hostname, string $domain): ?string
    {
        $hostname = $this->canonicalHostname($hostname);
        if ($hostname === '') return null;
        if (!str_contains($hostname, '.')) $hostname .= '.' . $domain;
        if (!str_ends_with($hostname, '.' . $domain) && $hostname !== $domain) return null;
        foreach (explode('.', $hostname) as $label) {
            if ($label === '' || strlen($label) > 63 || preg_match('/^(?!-)[a-z0-9-]+(?<!-)$/', $label) !== 1) return null;
        }
        return $hostname;
    }

    private function canonicalHostname(string $hostname): string
    {
        return strtolower(rtrim(trim($hostname), '.'));
    }

    private function shortHostname(string $hostname, string $domain): string
    {
        return $hostname === $domain ? '@' : (string) preg_replace('/\.' . preg_quote($domain, '/') . '$/', '', $hostname);
    }

    private function validAddress(string $address, string $type): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6) !== false;
    }

    private function ttlSeconds(string $ttl): ?int
    {
        if (ctype_digit($ttl)) return (int) $ttl;
        if (preg_match('/^(\d+)(s|m|h|d|w)$/i', trim($ttl), $match) !== 1) return null;
        return (int) $match[1] * match (strtolower($match[2])) { 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, default => 1 };
    }
}
