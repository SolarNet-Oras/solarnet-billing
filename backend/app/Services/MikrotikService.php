<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Query;
use Throwable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class MikrotikService
{
    private const BILLING_RULE_PREFIX = 'Solarnet Billing: suspended';
    private const SUSPENDED_ADDRESS_LIST = 'suspended_customers';
    private const PAYMENT_PORTAL_ADDRESS_LIST = 'solarnet_payment_portal';
    private const PAYMENT_SESSION_ADDRESS_LIST = 'solarnet_payment_sessions';
    private const PAYMENT_PORTAL_COMMENT_PREFIX = 'Solarnet Billing payment portal';
    private const THREAT_FEED_ADDRESS_LIST = 'solarnet_threat_feed';
    private const THREAT_FEED_RULE_PREFIX = 'SolarNet Threat Feed: manual block';
    private const QOS_OWNER_PREFIX = 'SolarNet-QoS:v1';
    private const DNS_OWNER_PREFIX = 'SolarNet-DNS:v1';
    /**
     * Customers are sent to this PayMongo-hosted page to choose GCash and
     * complete payment. Keep this deliberately narrow: allowing arbitrary
     * GCash domains would defeat the payment-only network policy.
     */
    private const PAYMENT_CHECKOUT_HOSTS = ['checkout.paymongo.com'];

    protected function makeConfig(Router $router, int $connectTimeout = 3, int $socketTimeout = 5): Config
    {
        return (new Config())
            ->set('host', $router->host)
            ->set('user', $router->username)
            ->set('pass', $router->password)
            ->set('port', $router->port)
            ->set('timeout', $connectTimeout)
            ->set('socket_timeout', $socketTimeout)
            ->set('attempts', 1)
            ->set('delay', 1);
    }

    /**
     * Test connection to MikroTik router
     * 
     * @param Router $router
     * @return array{success: bool, message: string, data: array|null}
     */
    public function testConnection(Router $router): array
    {
        try {
            // Create config
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            // Create client and connect
            $client = new Client($config);
            
            // Fetch system resource to get RouterOS version and uptime
            $query = new Query('/system/resource/print');
            $response = $client->query($query)->read();
            
            $systemInfo = $response[0] ?? [];
            
            $data = [
                'version' => $systemInfo['version'] ?? 'Unknown',
                'uptime' => $systemInfo['uptime'] ?? 'Unknown',
                'cpu_load' => $systemInfo['cpu-load'] ?? 'Unknown',
                'free_memory' => $systemInfo['free-memory'] ?? 'Unknown',
                'total_memory' => $systemInfo['total-memory'] ?? 'Unknown',
                'board_name' => $systemInfo['board-name'] ?? 'Unknown',
            ];
            
            // Update router record
            $router->update([
                'connection_status' => 'online',
                'routeros_version' => $data['version'],
                'last_connected_at' => now(),
            ]);
            
            return [
                'success' => true,
                'message' => 'Connected successfully to ' . $router->name,
                'data' => $data,
            ];
            
        } catch (Throwable $e) {
            Log::error('MikroTik connection failed', [
                'router_id' => $router->id,
                'host' => $router->host,
                'error' => $e->getMessage(),
            ]);
            
            // Update router status
            $router->update([
                'connection_status' => 'offline',
            ]);
            
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Read RouterOS DNS, static records, and DHCP network information without
     * changing a setting. Unknown records and every DHCP network remain
     * protected until an administrator explicitly includes them in a plan.
     */
    public function dnsBrandingDiscovery(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router, 3, 5));
            $errors = [];
            $read = function (string $path) use ($client, &$errors): array {
                try {
                    return $client->query(new Query($path))->read();
                } catch (Throwable $e) {
                    $errors[] = ['path' => $path, 'message' => $e->getMessage()];
                    return [];
                }
            };

            $dns = $read('/ip/dns/print')[0] ?? [];
            $static = $read('/ip/dns/static/print');
            $dhcpServers = $read('/ip/dhcp-server/print');
            $dhcpNetworks = $read('/ip/dhcp-server/network/print');
            $addresses = $read('/ip/address/print');
            $vlans = $read('/interface/vlan/print');
            $bridges = $read('/interface/bridge/print');
            // DNS adlists are optional on older RouterOS versions. A missing
            // menu is displayed as a warning, never treated as an error.
            $optionalErrors = [];
            try {
                $adlists = $client->query(new Query('/ip/dns/adlist/print'))->read();
            } catch (Throwable $e) {
                $adlists = [];
                $optionalErrors[] = ['path' => '/ip/dns/adlist/print', 'message' => $e->getMessage()];
            }

            if ($errors !== []) {
                return ['success' => false, 'message' => 'DNS discovery could not read every required RouterOS DNS/DHCP area. No changes were made.', 'data' => ['read_errors' => $errors]];
            }

            $addressByInterface = [];
            $privateManagementIps = [];
            foreach ($addresses as $address) {
                $interface = (string) ($address['interface'] ?? '');
                $cidr = (string) ($address['address'] ?? '');
                $ip = explode('/', $cidr, 2)[0];
                if ($interface !== '' && $ip !== '' && !isset($addressByInterface[$interface])) $addressByInterface[$interface] = $ip;
                if ($this->isPrivateIpv4($ip)) $privateManagementIps[] = ['address' => $ip, 'interface' => $interface, 'cidr' => $cidr];
            }

            $vlanByName = [];
            foreach ($vlans as $vlan) {
                if (!empty($vlan['name'])) $vlanByName[(string) $vlan['name']] = ['vlan_id' => $vlan['vlan-id'] ?? null, 'parent_interface' => $vlan['interface'] ?? null];
            }
            $bridgeNames = array_values(array_filter(array_map(fn (array $bridge) => $bridge['name'] ?? null, $bridges)));

            $networkByGateway = [];
            foreach ($dhcpNetworks as $network) {
                if (!empty($network['gateway'])) $networkByGateway[(string) $network['gateway']] = $network;
            }
            $customerNetworks = [];
            foreach ($dhcpServers as $server) {
                $interface = (string) ($server['interface'] ?? '');
                $gateway = $addressByInterface[$interface] ?? null;
                $network = $gateway ? ($networkByGateway[$gateway] ?? null) : null;
                $disabled = ($server['disabled'] ?? 'false') === 'true';
                $customerNetworks[] = [
                    'id' => $network['.id'] ?? null,
                    'server_name' => $server['name'] ?? null,
                    'interface' => $interface ?: null,
                    'vlan_id' => $vlanByName[$interface]['vlan_id'] ?? null,
                    'parent_interface' => $vlanByName[$interface]['parent_interface'] ?? null,
                    'is_bridge' => in_array($interface, $bridgeNames, true),
                    'network' => $network['address'] ?? null,
                    'gateway' => $gateway,
                    'dns_server' => $network['dns-server'] ?? '',
                    'server_disabled' => $disabled,
                    // Nothing is automatically managed. This only says the
                    // network can be selected after explicit approval.
                    'manageable' => !$disabled && !empty($network['.id']) && $this->isPrivateIpv4((string) $gateway),
                    'status' => !$disabled && $network ? (($network['dns-server'] ?? '') === $gateway ? 'solarnet_dns_enabled' : 'not_enabled') : 'protected_unknown',
                ];
            }

            $staticRecords = array_map(function (array $record): array {
                $address = (string) ($record['address'] ?? '');
                $type = strtoupper((string) ($record['type'] ?? ''));
                if (!in_array($type, ['A', 'AAAA'], true)) $type = str_contains($address, ':') ? 'AAAA' : 'A';
                $comment = (string) ($record['comment'] ?? '');
                return [
                    'id' => $record['.id'] ?? null,
                    'name' => $record['name'] ?? null,
                    'address' => $address ?: null,
                    'type' => $type,
                    'ttl' => $record['ttl'] ?? null,
                    'comment' => $comment,
                    'disabled' => ($record['disabled'] ?? 'false') === 'true',
                    'owned_by_solarnet' => str_starts_with($comment, self::DNS_OWNER_PREFIX),
                ];
            }, $static);
            $configuredServers = array_values(array_filter(array_map('trim', explode(',', (string) ($dns['servers'] ?? '')))));
            $dynamicServers = array_values(array_filter(array_map('trim', explode(',', (string) ($dns['dynamic-servers'] ?? '')))));
            $doh = trim((string) ($dns['use-doh-server'] ?? ''));

            return ['success' => true, 'data' => [
                'dns' => [
                    'allow_remote_requests' => ($dns['allow-remote-requests'] ?? 'false') === 'true',
                    'servers' => $configuredServers,
                    'dynamic_servers' => $dynamicServers,
                    'use_doh_server' => $doh ?: null,
                    'verify_doh_cert' => ($dns['verify-doh-cert'] ?? 'false') === 'true',
                    'cache_size' => $dns['cache-size'] ?? null,
                    'cache_max_ttl' => $dns['cache-max-ttl'] ?? null,
                ],
                'allow_remote_requests' => ($dns['allow-remote-requests'] ?? 'false') === 'true',
                'upstream_dns_available' => $configuredServers !== [] || $dynamicServers !== [] || $doh !== '',
                'static_records' => $staticRecords,
                'dhcp_networks' => $customerNetworks,
                'router_management_candidates' => $privateManagementIps,
                'dns_policy' => ['adlist_count' => count($adlists), 'optional_read_errors' => $optionalErrors],
                'compatibility' => [
                    'api_connected' => true,
                    'unknown_static_records_protected' => count(array_filter($staticRecords, fn (array $record) => !($record['owned_by_solarnet'] ?? false))),
                    'dhcp_networks_discovered' => count($customerNetworks),
                    'can_distribute_dns_without_router_change' => ($dns['allow-remote-requests'] ?? 'false') === 'true',
                ],
                'discovered_at' => now()->toIso8601String(),
            ]];
        } catch (Throwable $e) {
            Log::warning('Router DNS branding discovery failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'DNS discovery failed without changing RouterOS: ' . $e->getMessage()];
        }
    }

    /** Create and verify a RouterOS binary backup before DNS changes. */
    public function createDnsBackup(Router $router, string $backupName): array
    {
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $backupName) ?: 'solarnet-dns-backup';
        try {
            $client = new Client($this->makeConfig($router));
            $client->query((new Query('/system/backup/save'))->equal('name', $safeName))->read();
            usleep(500000);
            $files = $client->query(new Query('/file/print'))->read();
            $file = collect($files)->first(fn (array $item) => in_array((string) ($item['name'] ?? ''), [$safeName, $safeName . '.backup'], true));
            if (!$file) return ['success' => false, 'message' => 'RouterOS did not confirm the DNS backup file. DNS changes were blocked.'];
            return ['success' => true, 'backup_file' => $file['name'], 'message' => 'RouterOS DNS safety backup created and verified.'];
        } catch (Throwable $e) {
            Log::warning('Router DNS backup failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'DNS backup failed. DNS changes were blocked: ' . $e->getMessage()];
        }
    }

    /**
     * Apply only previously-previewed DNS static records and explicitly
     * selected DHCP DNS fields. It never changes /ip dns, firewall, NAT, WAN,
     * routes, addresses, queues, VLANs, or unknown records.
     */
    public function applyDnsBranding(Router $router, array $plan, string $auditToken): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $static = $client->query(new Query('/ip/dns/static/print'))->read();
            $byId = [];
            $byName = [];
            foreach ($static as $record) {
                if (!empty($record['.id'])) $byId[(string) $record['.id']] = $record;
                $name = $this->canonicalDnsName((string) ($record['name'] ?? ''));
                if ($name !== '') $byName[$name][] = $record;
            }
            $ownerComment = self::DNS_OWNER_PREFIX . ' audit=' . $auditToken;
            $created = 0;
            $removed = 0;
            $dhcpUpdated = 0;

            foreach ((array) ($plan['record_removals'] ?? []) as $removal) {
                $current = $byId[(string) ($removal['existing_id'] ?? '')] ?? null;
                if (!$current || !str_starts_with((string) ($current['comment'] ?? ''), self::DNS_OWNER_PREFIX)) {
                    throw new \RuntimeException('A SolarNet DNS record selected for removal changed after preview. No further DNS changes were made.');
                }
                $client->query((new Query('/ip/dns/static/remove'))->equal('.id', $current['.id']))->read();
                $removed++;
            }
            foreach ((array) ($plan['record_changes'] ?? []) as $record) {
                $action = $record['action'] ?? '';
                if ($action === 'replace_solarnet') {
                    $current = $byId[(string) ($record['existing_id'] ?? '')] ?? null;
                    if (!$current || !str_starts_with((string) ($current['comment'] ?? ''), self::DNS_OWNER_PREFIX)) {
                        throw new \RuntimeException('A SolarNet DNS record changed after preview. No replacement was made.');
                    }
                    $client->query((new Query('/ip/dns/static/remove'))->equal('.id', $current['.id']))->read();
                    $removed++;
                } elseif ($action === 'add_solarnet') {
                    $existing = $byName[$this->canonicalDnsName((string) $record['hostname'])] ?? [];
                    if (array_filter($existing, fn (array $item) => !str_starts_with((string) ($item['comment'] ?? ''), self::DNS_OWNER_PREFIX))) {
                        throw new \RuntimeException('A protected DNS record now uses ' . $record['hostname'] . '. No record was added.');
                    }
                }
                $description = trim((string) ($record['description'] ?? ''));
                $comment = $ownerComment . ($description !== '' ? '; ' . substr($description, 0, 240) : '');
                $this->addDnsStaticRecord($client, $record, $comment);
                $created++;
            }

            $networks = $client->query(new Query('/ip/dhcp-server/network/print'))->read();
            $networkById = [];
            foreach ($networks as $network) if (!empty($network['.id'])) $networkById[(string) $network['.id']] = $network;
            foreach ((array) ($plan['dhcp_changes'] ?? []) as $change) {
                $current = $networkById[(string) ($change['network_id'] ?? '')] ?? null;
                if (!$current
                    || (string) ($current['gateway'] ?? '') !== (string) ($change['gateway'] ?? '')
                    || (string) ($current['dns-server'] ?? '') !== (string) ($change['previous_dns_server'] ?? '')) {
                    throw new \RuntimeException('An approved DHCP network changed after preview. SolarNet did not overwrite its DNS server.');
                }
                $client->query(
                    (new Query('/ip/dhcp-server/network/set'))
                        ->equal('.id', $current['.id'])
                        ->equal('dns-server', (string) $change['new_dns_server'])
                )->read();
                $dhcpUpdated++;
            }

            return ['success' => true, 'message' => "Applied {$created} SolarNet DNS record(s) and {$dhcpUpdated} explicitly-approved DHCP DNS update(s).", 'data' => ['records_created' => $created, 'records_removed' => $removed, 'dhcp_updated' => $dhcpUpdated]];
        } catch (Throwable $e) {
            Log::warning('Router DNS branding apply failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'DNS changes were not fully applied: ' . $e->getMessage()];
        }
    }

    /** Test static records plus external DNS without changing RouterOS. */
    public function verifyDnsBranding(Router $router, array $plan, string $auditToken): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $static = $client->query(new Query('/ip/dns/static/print'))->read();
            $expected = array_values(array_filter((array) ($plan['record_changes'] ?? []), fn (array $record) => in_array($record['action'] ?? '', ['add_solarnet', 'replace_solarnet'], true)));
            $internal = [];
            foreach ($expected as $record) {
                $matching = collect($static)->first(fn (array $current) => $this->canonicalDnsName((string) ($current['name'] ?? '')) === $this->canonicalDnsName((string) $record['hostname'])
                    && (string) ($current['address'] ?? '') === (string) $record['address']
                    && str_starts_with((string) ($current['comment'] ?? ''), self::DNS_OWNER_PREFIX . ' audit=' . $auditToken));
                $lookup = $this->routerDnsLookup($client, (string) $record['hostname']);
                $internal[] = [
                    'hostname' => $record['hostname'],
                    'expected_address' => $record['address'],
                    'static_present' => (bool) $matching,
                    'resolved_address' => $lookup['address'],
                    'ok' => (bool) $matching && $lookup['ok'] && $lookup['address'] === $record['address'],
                    'message' => $lookup['message'],
                ];
            }
            $external = [
                $this->routerDnsLookup($client, 'google.com'),
                $this->routerDnsLookup($client, 'cloudflare.com'),
            ];
            $ping = $this->routerPingOnce($client, '1.1.1.1');
            $internalOk = count($internal) > 0 && !array_filter($internal, fn (array $result) => !$result['ok']);
            $externalOk = !array_filter($external, fn (array $result) => !$result['ok']);

            return ['success' => $internalOk && $externalOk, 'data' => [
                'internal_records' => $internal,
                'external_dns' => $external,
                'external_connectivity' => $ping,
                'verified_at' => now()->toIso8601String(),
            ], 'message' => $internalOk && $externalOk
                ? 'Internal DNS records and external DNS resolution were verified. WAN, public IP, NAT, routing, firewall, VLAN, QoS, and billing were unchanged.'
                : 'DNS verification did not pass. SolarNet will roll back only this audit\'s DNS records and DHCP DNS values.'];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'DNS verification failed: ' . $e->getMessage(), 'data' => ['verified_at' => now()->toIso8601String()]];
        }
    }

    /** Remove only records tagged with this audit and restore prior SolarNet DNS/DHCP values. */
    public function rollbackDnsBranding(Router $router, array $plan, string $auditToken): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $static = $client->query(new Query('/ip/dns/static/print'))->read();
            $tag = self::DNS_OWNER_PREFIX . ' audit=' . $auditToken;
            $removed = 0;
            foreach ($static as $record) {
                if (!empty($record['.id']) && str_starts_with((string) ($record['comment'] ?? ''), $tag)) {
                    $client->query((new Query('/ip/dns/static/remove'))->equal('.id', $record['.id']))->read();
                    $removed++;
                }
            }

            $currentNames = array_map(fn (array $record) => $this->canonicalDnsName((string) ($record['name'] ?? '')), $client->query(new Query('/ip/dns/static/print'))->read());
            $restored = 0;
            foreach (array_merge((array) ($plan['record_changes'] ?? []), (array) ($plan['record_removals'] ?? [])) as $change) {
                $previous = $change['previous'] ?? null;
                if (!is_array($previous) || empty($previous['name']) || empty($previous['address'])) continue;
                $name = $this->canonicalDnsName((string) $previous['name']);
                if (in_array($name, $currentNames, true)) continue; // never overwrite a later administrator record
                $this->addDnsStaticRecord($client, [
                    'hostname' => $previous['name'],
                    'address' => $previous['address'],
                    'ttl_seconds' => $this->dnsTtlSeconds((string) ($previous['ttl'] ?? '')) ?? 86400,
                ], (string) ($previous['comment'] ?? self::DNS_OWNER_PREFIX));
                $restored++;
            }

            $networks = $client->query(new Query('/ip/dhcp-server/network/print'))->read();
            $networkById = [];
            foreach ($networks as $network) if (!empty($network['.id'])) $networkById[(string) $network['.id']] = $network;
            $dhcpRestored = 0;
            $skipped = [];
            foreach ((array) ($plan['dhcp_changes'] ?? []) as $change) {
                $current = $networkById[(string) ($change['network_id'] ?? '')] ?? null;
                if (!$current) {
                    $skipped[] = $change['network'] ?? $change['network_id'] ?? 'unknown DHCP network';
                    continue;
                }
                // It was never changed by this audit (or was already restored),
                // so leave it alone and do not turn a safe rollback into a
                // false failure.
                if ((string) ($current['dns-server'] ?? '') === (string) ($change['previous_dns_server'] ?? '')) continue;
                if ((string) ($current['dns-server'] ?? '') !== (string) ($change['new_dns_server'] ?? '')) {
                    $skipped[] = $change['network'] ?? $change['network_id'] ?? 'unknown DHCP network';
                    continue;
                }
                $query = (new Query('/ip/dhcp-server/network/set'))->equal('.id', $current['.id']);
                $query->equal('dns-server', (string) ($change['previous_dns_server'] ?? ''));
                $client->query($query)->read();
                $dhcpRestored++;
            }
            return ['success' => $skipped === [], 'message' => $skipped === []
                ? "Rolled back {$removed} audit-owned DNS record(s), restored {$restored} earlier SolarNet record(s), and restored {$dhcpRestored} DHCP DNS value(s)."
                : 'Rollback removed audit-owned records but did not overwrite DHCP networks changed by another administrator: ' . implode(', ', $skipped) . '.', 'data' => compact('removed', 'restored', 'dhcpRestored', 'skipped')];
        } catch (Throwable $e) {
            Log::warning('Router DNS branding rollback failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'DNS rollback could not be completed: ' . $e->getMessage()];
        }
    }

    /** Read-only test endpoint for current router DNS behavior. */
    public function testDnsBranding(Router $router, array $hostnames): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $names = array_values(array_unique(array_filter(array_merge($hostnames, ['google.com', 'cloudflare.com']))));
            $results = array_map(fn (string $hostname) => $this->routerDnsLookup($client, $hostname), $names);
            return ['success' => true, 'data' => ['results' => $results, 'tested_at' => now()->toIso8601String()], 'message' => array_filter($results, fn (array $result) => !$result['ok'])
                ? 'Read-only DNS test completed with one or more unresolved names. This does not change the router.'
                : 'Read-only DNS test completed.'];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'DNS test failed without changing RouterOS: ' . $e->getMessage()];
        }
    }

    private function addDnsStaticRecord(Client $client, array $record, string $comment): void
    {
        $client->query(
            (new Query('/ip/dns/static/add'))
                ->equal('name', (string) $record['hostname'])
                ->equal('address', (string) $record['address'])
                ->equal('ttl', (string) ((int) ($record['ttl_seconds'] ?? 86400)) . 's')
                ->equal('comment', $comment)
        )->read();
    }

    private function routerDnsLookup(Client $client, string $hostname): array
    {
        try {
            $rows = $client->query((new Query('/resolve'))->equal('name', $hostname))->read();
            $answer = null;
            foreach ($rows as $row) {
                foreach (['ret', 'address', 'data'] as $key) {
                    if (!empty($row[$key]) && filter_var($row[$key], FILTER_VALIDATE_IP)) $answer = $row[$key];
                }
            }
            return ['hostname' => $hostname, 'address' => $answer, 'ok' => $answer !== null, 'message' => $answer ? 'Resolved by RouterOS.' : 'RouterOS returned no IP address.'];
        } catch (Throwable $e) {
            return ['hostname' => $hostname, 'address' => null, 'ok' => false, 'message' => 'RouterOS DNS lookup failed: ' . $e->getMessage()];
        }
    }

    private function routerPingOnce(Client $client, string $address): array
    {
        try {
            $rows = $client->query((new Query('/ping'))->equal('address', $address)->equal('count', '1'))->read();
            $received = array_filter($rows, fn (array $row) => ($row['status'] ?? '') !== 'timeout');
            return ['target' => $address, 'ok' => $received !== [], 'message' => $received !== [] ? 'RouterOS external reachability sample succeeded.' : 'RouterOS external reachability sample timed out.'];
        } catch (Throwable $e) {
            return ['target' => $address, 'ok' => false, 'message' => 'RouterOS ping is unavailable: ' . $e->getMessage()];
        }
    }

    private function canonicalDnsName(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }

    private function dnsTtlSeconds(string $ttl): ?int
    {
        if (ctype_digit($ttl)) return (int) $ttl;
        if (preg_match('/^(\d+)(s|m|h|d|w)$/i', trim($ttl), $match) !== 1) return null;
        return (int) $match[1] * match (strtolower($match[2])) { 'm' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800, default => 1 };
    }

    private function isPrivateIpv4(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) return false;
        $value = ip2long($ip);
        return ($value >= ip2long('10.0.0.0') && $value <= ip2long('10.255.255.255'))
            || ($value >= ip2long('172.16.0.0') && $value <= ip2long('172.31.255.255'))
            || ($value >= ip2long('192.168.0.0') && $value <= ip2long('192.168.255.255'));
    }

    /**
     * Read-only RouterOS monitoring snapshot. It never creates, removes, or
     * changes a RouterOS rule. Interface rates are calculated from successive
     * byte-counter samples kept briefly in the application cache.
     */
    public function monitoringSnapshot(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $resource = $client->query(new Query('/system/resource/print'))->read()[0] ?? [];
            $interfaces = $client->query(new Query('/interface/print'))->read();
            $filters = $client->query(new Query('/ip/firewall/filter/print'))->read();
            $addressLists = $client->query(new Query('/ip/firewall/address-list/print'))->read();

            $rxBytes = 0;
            $txBytes = 0;
            $runningInterfaces = 0;
            foreach ($interfaces as $interface) {
                if (($interface['running'] ?? 'false') === 'true') $runningInterfaces++;
                $rxBytes += (int) ($interface['rx-byte'] ?? 0);
                $txBytes += (int) ($interface['tx-byte'] ?? 0);
            }

            $now = microtime(true);
            $cacheKey = 'router-monitoring-sample:' . $router->id;
            $previous = Cache::get($cacheKey);
            $elapsed = is_array($previous) ? max(0.001, $now - (float) ($previous['at'] ?? $now)) : null;
            $rxBps = $elapsed ? max(0, (int) (($rxBytes - (int) ($previous['rx_bytes'] ?? $rxBytes)) * 8 / $elapsed)) : null;
            $txBps = $elapsed ? max(0, (int) (($txBytes - (int) ($previous['tx_bytes'] ?? $txBytes)) * 8 / $elapsed)) : null;
            Cache::put($cacheKey, ['rx_bytes' => $rxBytes, 'tx_bytes' => $txBytes, 'at' => $now], now()->addMinutes(5));

            $dropRules = array_values(array_filter($filters, fn (array $rule) => ($rule['disabled'] ?? 'false') !== 'true' && ($rule['action'] ?? '') === 'drop'));
            $keywordPattern = '/virus|malware|threat|infect|botnet|blacklist|block/i';
            $threatRules = array_values(array_filter($dropRules, fn (array $rule) => preg_match($keywordPattern, (string) ($rule['comment'] ?? '')) === 1));
            $threatLists = array_values(array_filter($addressLists, fn (array $entry) => preg_match($keywordPattern, (string) ($entry['list'] ?? '')) === 1));
            $blockedPackets = array_sum(array_map(fn (array $rule) => (int) ($rule['packets'] ?? 0), $threatRules));
            // These are identification details for an operator, not a verdict
            // that a client device is infected. Keep the response bounded so a
            // large administrator-maintained address list cannot slow down the
            // regular five-second dashboard monitor.
            $detailLimit = 50;
            $threatRuleDetails = array_map(fn (array $rule) => [
                'id' => (string) ($rule['.id'] ?? ''),
                'comment' => trim((string) ($rule['comment'] ?? '')) ?: 'Unnamed threat-related drop rule',
                'chain' => (string) ($rule['chain'] ?? ''),
                'action' => (string) ($rule['action'] ?? ''),
                'packets' => (int) ($rule['packets'] ?? 0),
                'bytes' => (int) ($rule['bytes'] ?? 0),
                'match_reason' => 'Enabled drop rule comment matches the threat-signal keywords.',
            ], array_slice($threatRules, 0, $detailLimit));
            $threatListDetails = array_map(fn (array $entry) => [
                'id' => (string) ($entry['.id'] ?? ''),
                'list' => (string) ($entry['list'] ?? ''),
                'address' => (string) ($entry['address'] ?? ''),
                'comment' => trim((string) ($entry['comment'] ?? '')) ?: null,
                'dynamic' => ($entry['dynamic'] ?? 'false') === 'true',
                'timeout' => ($entry['timeout'] ?? '') !== '' ? (string) $entry['timeout'] : null,
                'match_reason' => 'Address-list name matches the threat-signal keywords.',
            ], array_slice($threatLists, 0, $detailLimit));

            return [
                'success' => true,
                'data' => [
                    'cpu_load' => (int) ($resource['cpu-load'] ?? 0),
                    'free_memory' => (int) ($resource['free-memory'] ?? 0),
                    'total_memory' => (int) ($resource['total-memory'] ?? 0),
                    'uptime' => $resource['uptime'] ?? null,
                    'running_interfaces' => $runningInterfaces,
                    'rx_bps' => $rxBps,
                    'tx_bps' => $txBps,
                    'traffic_sampled' => $elapsed !== null,
                    'threat_status' => count($dropRules) > 0 ? 'protected' : 'monitoring',
                    'firewall_drop_rules' => count($dropRules),
                    'threat_signal_rules' => count($threatRules),
                    'threat_address_list_entries' => count($threatLists),
                    'threat_blocked_packets' => $blockedPackets,
                    'threat_signal_details' => [
                        'firewall_rules' => $threatRuleDetails,
                        'address_list_entries' => $threatListDetails,
                        'firewall_rules_hidden' => max(0, count($threatRules) - count($threatRuleDetails)),
                        'address_list_entries_hidden' => max(0, count($threatLists) - count($threatListDetails)),
                    ],
                    'scanned_at' => now()->toIso8601String(),
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Router monitoring read failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Monitoring read failed: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Read every configuration area that can make a "new router" unsafe to
     * provision. This is intentionally read-only and deliberately conservative:
     * an unreadable required area or an unknown production-looking setting stops
     * the provisioning workflow rather than being guessed or overwritten.
     */
    public function cleanProvisioningDiscovery(Router $router): array
    {
        try {
            // Discovery reads many RouterOS configuration areas. Bound each
            // read separately so one unreachable menu cannot consume the
            // whole request. These are read-only commands; nothing is changed.
            $client = new Client($this->makeConfig($router, 3, 3));
            $errors = [];
            $read = function (string $path) use ($client, &$errors): array {
                try {
                    return $client->query(new Query($path))->read();
                } catch (Throwable $e) {
                    $errors[] = ['path' => $path, 'message' => $e->getMessage()];
                    return [];
                }
            };

            $resource = $read('/system/resource/print')[0] ?? [];
            $interfaces = $read('/interface/print');
            $bridges = $read('/interface/bridge/print');
            $vlans = $read('/interface/vlan/print');
            $addresses = $read('/ip/address/print');
            $dhcpServers = $read('/ip/dhcp-server/print');
            $dhcpClients = $read('/ip/dhcp-client/print');
            $dhcpPools = $read('/ip/pool/print');
            $dhcpNetworks = $read('/ip/dhcp-server/network/print');
            $routes = $read('/ip/route/print');
            $filters = $read('/ip/firewall/filter/print');
            $nat = $read('/ip/firewall/nat/print');
            $mangle = $read('/ip/firewall/mangle/print');
            $services = $read('/ip/service/print');
            $simpleQueues = $read('/queue/simple/print');
            $queueTrees = $read('/queue/tree/print');
            $queueTypes = $read('/queue/type/print');
            $hotspots = $read('/ip/hotspot/print');
            $hotspotProfiles = $read('/ip/hotspot/profile/print');
            $pppoeServers = $read('/interface/pppoe-server/server/print');
            $pppoeClients = $read('/interface/pppoe-client/print');
            $pppSecrets = $read('/ppp/secret/print');
            $pppProfiles = $read('/ppp/profile/print');
            $wireguard = $read('/interface/wireguard/print');
            $routingRules = $read('/routing/rule/print');
            $routingTables = $read('/routing/table/print');
            $scripts = $read('/system/script/print');
            $schedulers = $read('/system/scheduler/print');

            $interfaceNames = array_values(array_filter(array_map(fn (array $item) => $item['name'] ?? null, $interfaces)));
            $runningInterfaces = array_values(array_filter(array_map(
                fn (array $item) => (($item['running'] ?? 'false') === 'true' && ($item['disabled'] ?? 'false') !== 'true') ? ($item['name'] ?? null) : null,
                $interfaces,
            )));
            $customerParentCandidates = array_values(array_filter(array_map(
                fn (array $item) => strtolower((string) ($item['type'] ?? '')) === 'ether'
                    && ($item['disabled'] ?? 'false') !== 'true'
                    ? ($item['name'] ?? null)
                    : null,
                $interfaces,
            )));
            $defaultRoutes = array_values(array_filter($routes, fn (array $route) => in_array((string) ($route['dst-address'] ?? ''), ['0.0.0.0/0', '::/0'], true) && ($route['disabled'] ?? 'false') !== 'true'));
            $wanCandidates = [];
            foreach ($defaultRoutes as $route) {
                $gateway = (string) ($route['gateway'] ?? $route['immediate-gw'] ?? '');
                $interface = null;
                if (preg_match('/%([^\s,]+)/', $gateway, $match) === 1) $interface = $match[1];
                if ($interface === null && in_array($gateway, $interfaceNames, true)) $interface = $gateway;
                $wanCandidates[] = ['gateway' => $gateway ?: null, 'interface' => $interface, 'distance' => $route['distance'] ?? null];
            }
            foreach ($dhcpClients as $dhcpClient) {
                $interface = $dhcpClient['interface'] ?? null;
                if ($interface && ($dhcpClient['disabled'] ?? 'false') !== 'true' && !array_filter($wanCandidates, fn (array $candidate) => ($candidate['interface'] ?? null) === $interface)) {
                    $wanCandidates[] = ['gateway' => $dhcpClient['gateway'] ?? null, 'interface' => $interface, 'distance' => null];
                }
            }

            $isDefaultRule = fn (array $rule): bool => $rule === [] || preg_match('/defconf|default configuration|default rule/i', (string) ($rule['comment'] ?? '')) === 1;
            $apiPorts = array_values(array_unique(array_filter(array_map(
                fn (array $service) => strtolower((string) ($service['name'] ?? '')) === 'api' && ($service['disabled'] ?? 'false') !== 'true' ? (string) ($service['port'] ?? '') : null,
                $services,
            ))));
            $baselineMasqueradeNat = array_values(array_filter($nat, fn (array $rule) => self::isBaselineMasqueradeNat($rule)));
            $baselineApiRules = array_values(array_filter($filters, fn (array $rule) => self::isBaselineApiFirewallRule($rule, $apiPorts)));
            $baselineVpnManagementRules = array_values(array_filter($filters, fn (array $rule) => self::isBaselineVpnManagementRule($rule, $interfaces)));
            $baselineBillingRules = array_values(array_filter($filters, fn (array $rule) => self::isBaselineBillingFirewallRule($rule)));
            $unacceptedFirewall = array_values(array_filter($filters, fn (array $rule) => !$isDefaultRule($rule) && !self::isBaselineApiFirewallRule($rule, $apiPorts) && !self::isBaselineVpnManagementRule($rule, $interfaces) && !self::isBaselineBillingFirewallRule($rule)));
            $unacceptedNat = array_values(array_filter($nat, fn (array $rule) => !$isDefaultRule($rule) && !self::isBaselineMasqueradeNat($rule)));
            $customRoutes = array_values(array_filter($routes, fn (array $route) => ($route['dynamic'] ?? 'false') !== 'true' && !in_array((string) ($route['dst-address'] ?? ''), ['0.0.0.0/0', '::/0'], true)));
            $customPppProfiles = array_values(array_filter($pppProfiles, fn (array $profile) => !in_array(strtolower((string) ($profile['name'] ?? '')), ['default', 'default-encryption'], true)));
            // RouterOS commonly includes the default HotSpot profile even before
            // HotSpot is configured. Only an actual HotSpot server or a custom
            // profile makes the clean-router workflow unsafe to continue.
            $customHotspotProfiles = array_values(array_filter($hotspotProfiles, fn (array $profile) => strtolower((string) ($profile['name'] ?? '')) !== 'default'));
            // The minimal API input rule may be labelled SolarNet. It is
            // connectivity baseline, not an existing customer/billing setup.
            $hasSolarNet = $this->containsSolarNetMarker(array_merge($unacceptedFirewall, $unacceptedNat, $mangle, $simpleQueues, $queueTrees, $hotspots, $scripts, $schedulers));
            $factoryDhcpBaseline = self::isFactoryDhcpBaseline($dhcpServers, $dhcpPools, $dhcpNetworks, $bridges);
            $billingBaselineComplete = count($baselineBillingRules) === 5
                && count(array_unique(array_map(fn (array $rule) => (string) ($rule['comment'] ?? ''), $baselineBillingRules))) === 5;
            $pppoeDetected = count($pppoeServers) + count($pppoeClients) + count($pppSecrets) > 0;
            $baselineWarnings = [];
            foreach ($baselineApiRules as $rule) {
                if (empty($rule['src-address']) && empty($rule['src-address-list'])) {
                    $baselineWarnings[] = 'The preserved API input rule has no source restriction. Restrict access at the VPN or port-forward gateway.';
                    break;
                }
            }
            foreach ($baselineVpnManagementRules as $rule) {
                $baselineWarnings[] = sprintf(
                    'The preserved management rule accepts router input from the %s tunnel. SolarNet will not widen, replace, or remove this administrator-owned rule.',
                    (string) ($rule['in-interface'] ?? 'VPN')
                );
            }
            foreach ($services as $service) {
                if (in_array(strtolower((string) ($service['name'] ?? '')), ['api', 'api-ssl'], true)
                    && ($service['disabled'] ?? 'false') !== 'true'
                    && in_array((string) ($service['address'] ?? ''), ['', '0.0.0.0/0'], true)) {
                    $baselineWarnings[] = sprintf(
                        'RouterOS %s service port %s has no service-level source restriction. Keep WAN input blocked and reach it only through the management VPN.',
                        (string) ($service['name'] ?? 'API'),
                        (string) ($service['port'] ?? '?')
                    );
                }
            }

            $blockers = [];
            if ($errors !== []) $blockers[] = 'Router discovery is incomplete. SolarNet will not provision a router when a required RouterOS area cannot be read.';
            if ($pppoeDetected) $blockers[] = 'PPPoE DETECTED. SolarNet provisioning uses IPoE only and will not migrate, disable, or delete PPPoE automatically.';
            if ($hotspots !== [] || $customHotspotProfiles !== []) $blockers[] = 'Existing HotSpot configuration was detected.';
            if ($vlans !== []) $blockers[] = 'Existing VLAN interfaces were detected.';
            // Pools and network rows are inert without an enabled DHCP server.
            // Preserve those dormant administrator records instead of deleting
            // or treating them as a running customer network.
            if (self::hasBlockingDhcpConfiguration($dhcpServers, $factoryDhcpBaseline)) $blockers[] = 'Existing enabled DHCP is not one coherent private single-bridge server, pool, and network baseline.';
            if ($simpleQueues !== [] || $queueTrees !== []) $blockers[] = 'Existing Simple Queue or Queue Tree configuration was detected.';
            if ($mangle !== []) $blockers[] = 'Existing firewall mangle rules were detected.';
            if ($unacceptedFirewall !== [] || $unacceptedNat !== []) $blockers[] = 'Existing non-baseline firewall or NAT rules were detected. Only standard srcnat masquerade and a TCP input allow rule for the enabled RouterOS API port are accepted.';
            if ($customRoutes !== [] || $routingRules !== [] || count($routingTables) > 1) $blockers[] = 'Existing production routing, policy routing, or additional routing tables were detected.';
            if ($wireguard !== []) $blockers[] = 'Existing WireGuard configuration was detected. SolarNet will not alter or build on an existing VPN router automatically.';
            if ($scripts !== [] || $schedulers !== []) $blockers[] = 'Existing RouterOS scripts or schedulers were detected.';
            if ($baselineBillingRules !== [] && !$billingBaselineComplete) $blockers[] = 'An incomplete SolarNet billing firewall baseline was detected.';
            if ($hasSolarNet) $blockers[] = 'Unknown or non-baseline SolarNet configuration was detected.';
            if (count($bridges) > 1) $blockers[] = 'More than one bridge was detected; automatic customer topology selection would be unsafe.';

            return [
                'success' => true,
                'data' => [
                    'api_authenticated' => true,
                    'routeros_version' => $resource['version'] ?? null,
                    'board_name' => $resource['board-name'] ?? null,
                    'architecture' => $resource['architecture-name'] ?? null,
                    'cpu_load' => (int) ($resource['cpu-load'] ?? 0),
                    'free_memory' => (int) ($resource['free-memory'] ?? 0),
                    'total_memory' => (int) ($resource['total-memory'] ?? 0),
                    'free_storage' => (int) ($resource['free-hdd-space'] ?? 0),
                    'total_storage' => (int) ($resource['total-hdd-space'] ?? 0),
                    'interfaces' => array_map(fn (array $item) => ['name' => $item['name'] ?? null, 'type' => $item['type'] ?? null, 'running' => ($item['running'] ?? 'false') === 'true', 'disabled' => ($item['disabled'] ?? 'false') === 'true'], $interfaces),
                    'running_interfaces' => $runningInterfaces,
                    'customer_parent_candidates' => $customerParentCandidates,
                    'bridges' => array_values(array_filter(array_map(fn (array $item) => $item['name'] ?? null, $bridges))),
                    'existing_addresses' => array_values(array_filter(array_map(fn (array $item) => $item['address'] ?? null, $addresses))),
                    'wan_candidates' => $wanCandidates,
                    'wan_auto_detected' => count($wanCandidates) === 1 && !empty($wanCandidates[0]['interface']),
                    'counts' => [
                        'vlans' => count($vlans), 'ip_addresses' => count($addresses), 'dhcp_servers' => count($dhcpServers), 'dhcp_clients' => count($dhcpClients), 'dhcp_pools' => count($dhcpPools),
                        'routes' => count($routes), 'firewall_filters' => count($filters), 'firewall_nat' => count($nat), 'baseline_masquerade_nat_rules' => count($baselineMasqueradeNat), 'baseline_api_input_rules' => count($baselineApiRules), 'baseline_vpn_management_rules' => count($baselineVpnManagementRules), 'baseline_billing_rules' => count($baselineBillingRules), 'unaccepted_firewall_rules' => count($unacceptedFirewall), 'unaccepted_nat_rules' => count($unacceptedNat), 'mangle' => count($mangle),
                        'simple_queues' => count($simpleQueues), 'queue_trees' => count($queueTrees), 'queue_types' => count($queueTypes),
                        'hotspots' => count($hotspots), 'custom_hotspot_profiles' => count($customHotspotProfiles), 'pppoe_servers' => count($pppoeServers), 'pppoe_clients' => count($pppoeClients),
                        'ppp_secrets' => count($pppSecrets), 'custom_ppp_profiles' => count($customPppProfiles), 'wireguard' => count($wireguard),
                        'scripts' => count($scripts), 'schedulers' => count($schedulers),
                    ],
                    'fq_codel_available' => (bool) array_filter($queueTypes, fn (array $type) => str_contains(strtolower((string) (($type['name'] ?? '') . ' ' . ($type['kind'] ?? ''))), 'fq-codel')),
                    'fasttrack_enabled' => (bool) array_filter($filters, fn (array $rule) => ($rule['disabled'] ?? 'false') !== 'true' && ($rule['action'] ?? '') === 'fasttrack-connection'),
                    'default_firewall_preserved' => $unacceptedFirewall === [] && $unacceptedNat === [],
                    'baseline_connectivity' => [
                        'masquerade_nat_rules' => count($baselineMasqueradeNat),
                        'api_input_rules' => count($baselineApiRules),
                        'vpn_management_rules' => count($baselineVpnManagementRules),
                        'api_service_ports' => $apiPorts,
                        'factory_dhcp_preserved' => $factoryDhcpBaseline,
                        'billing_rules_preserved' => $billingBaselineComplete,
                        'warnings' => $baselineWarnings,
                    ],
                    'existing_solarnet_detected' => $hasSolarNet,
                    'pppoe_detected' => $pppoeDetected,
                    'blockers' => $blockers,
                    'clean' => $blockers === [],
                    'read_errors' => $errors,
                    'discovered_at' => now()->toIso8601String(),
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Clean router provisioning discovery failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Router discovery failed before provisioning: ' . $e->getMessage()];
        }
    }

    /** Verify only the resources that a clean SolarNet provisioning plan owns. */
    public function verifyCleanProvisioning(Router $router, array $plan): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $names = $plan['resource_names'] ?? [];
            $vlan = $client->query((new Query('/interface/vlan/print'))->where('name', (string) ($names['customer_vlan'] ?? '')))->read()[0] ?? null;
            $dhcp = $client->query((new Query('/ip/dhcp-server/print'))->where('name', (string) ($names['customer_dhcp'] ?? '')))->read()[0] ?? null;
            $pool = $client->query((new Query('/ip/pool/print'))->where('name', (string) ($names['customer_pool'] ?? '')))->read()[0] ?? null;
            $addresses = $client->query((new Query('/ip/address/print'))->where('comment', self::provisioningComment('customer gateway')))->read();
            $pppoeServers = $client->query(new Query('/interface/pppoe-server/server/print'))->read();
            $pppoeClients = $client->query(new Query('/interface/pppoe-client/print'))->read();
            $pppSecrets = $client->query(new Query('/ppp/secret/print'))->read();
            $queues = $client->query(new Query('/queue/simple/print'))->read();
            $billing = $this->billingAccessRulesStatus($router);

            $checks = [
                'customer_vlan' => $vlan !== null,
                'customer_dhcp' => $dhcp !== null,
                'customer_pool' => $pool !== null,
                'customer_gateway' => $addresses !== [],
                'pppoe_absent' => $pppoeServers === [] && $pppoeClients === [] && $pppSecrets === [],
                'no_customer_queues_created' => $queues === [],
                'billing_access' => (bool) ($billing['installed'] ?? false),
            ];
            if (($plan['captive_portal']['enabled'] ?? false) === true) {
                $portal = $client->query((new Query('/ip/hotspot/print'))->where('name', (string) ($names['portal_hotspot'] ?? '')))->read()[0] ?? null;
                $checks['isolated_captive_portal'] = $portal !== null;
            }

            return [
                'success' => !in_array(false, $checks, true),
                'data' => [
                    'checks' => $checks,
                    'client_test_required' => true,
                    'client_test_note' => 'Base infrastructure is verified. Connect one IPoE ONU/OLT client and verify DHCP, DNS, Internet, billing queue creation, and suspension policy before declaring the router production-ready.',
                    'verified_at' => now()->toIso8601String(),
                ],
                'message' => !in_array(false, $checks, true)
                    ? 'SolarNet base infrastructure was verified. An IPoE client acceptance test is still required before production use.'
                    : 'One or more expected SolarNet base resources could not be verified.',
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Provisioning verification failed: ' . $e->getMessage()];
        }
    }

    /** Apply each owned provisioning resource directly so RouterOS API errors cannot be hidden inside a script. */
    public function applyCleanProvisioningPlan(Router $router, array $plan): array
    {
        $created = [];
        try {
            $client = new Client($this->makeConfig($router));
            $names = $plan['resource_names'];
            $ensure = function (string $menu, string $matchField, string $matchValue, array $attributes, string $resourceKey) use ($client, &$created): void {
                $existing = $client->query((new Query($menu . '/print'))->where($matchField, $matchValue))->read();
                if ($existing !== []) return;
                $query = new Query($menu . '/add');
                foreach ($attributes as $field => $value) $query->equal($field, (string) $value);
                $client->query($query)->read();
                // Record immediately after RouterOS accepts the add command so
                // rollback still owns it if the following verification read
                // times out or fails.
                $created[] = $resourceKey;
                $verified = $client->query((new Query($menu . '/print'))->where($matchField, $matchValue))->read();
                if ($verified === []) throw new \RuntimeException("RouterOS did not create {$resourceKey}.");
            };

            $ensure('/interface/vlan', 'name', $names['customer_vlan'], ['name' => $names['customer_vlan'], 'interface' => $plan['customer_parent_interface'], 'vlan-id' => $plan['customer_vlan_id'], 'comment' => self::provisioningComment('customer VLAN')], 'customer_vlan');
            $ensure('/ip/address', 'comment', self::provisioningComment('customer gateway'), ['address' => $plan['customer_gateway_cidr'], 'interface' => $names['customer_vlan'], 'comment' => self::provisioningComment('customer gateway')], 'customer_gateway');
            $ensure('/ip/pool', 'name', $names['customer_pool'], ['name' => $names['customer_pool'], 'ranges' => $plan['customer_dhcp_pool']], 'customer_pool');
            $ensure('/ip/dhcp-server', 'name', $names['customer_dhcp'], ['name' => $names['customer_dhcp'], 'interface' => $names['customer_vlan'], 'address-pool' => $names['customer_pool'], 'lease-time' => '30m', 'disabled' => 'no', 'comment' => self::provisioningComment('customer DHCP')], 'customer_dhcp');
            $ensure('/ip/dhcp-server/network', 'comment', self::provisioningComment('customer DHCP network'), ['address' => $plan['customer_network_cidr'], 'gateway' => explode('/', $plan['customer_gateway_cidr'])[0], 'dns-server' => implode(',', $plan['dns_servers']), 'comment' => self::provisioningComment('customer DHCP network')], 'customer_network');
            if (($plan['create_nat'] ?? false) === true) {
                $ensure('/ip/firewall/nat', 'comment', self::provisioningComment('customer NAT'), ['chain' => 'srcnat', 'out-interface' => $plan['wan_interface'], 'action' => 'masquerade', 'comment' => self::provisioningComment('customer NAT')], 'customer_nat');
            }

            if (($plan['captive_portal']['enabled'] ?? false) === true) {
                $portal = $plan['captive_portal'];
                $ensure('/interface/vlan', 'name', $names['portal_vlan'], ['name' => $names['portal_vlan'], 'interface' => $plan['customer_parent_interface'], 'vlan-id' => $portal['vlan_id'], 'comment' => self::provisioningComment('portal VLAN')], 'portal_vlan');
                $ensure('/ip/address', 'comment', self::provisioningComment('portal gateway'), ['address' => $portal['gateway_cidr'], 'interface' => $names['portal_vlan'], 'comment' => self::provisioningComment('portal gateway')], 'portal_gateway');
                $ensure('/ip/pool', 'name', $names['portal_pool'], ['name' => $names['portal_pool'], 'ranges' => $portal['dhcp_pool']], 'portal_pool');
                $ensure('/ip/dhcp-server', 'name', $names['portal_dhcp'], ['name' => $names['portal_dhcp'], 'interface' => $names['portal_vlan'], 'address-pool' => $names['portal_pool'], 'lease-time' => '30m', 'disabled' => 'no', 'comment' => self::provisioningComment('portal DHCP')], 'portal_dhcp');
                $ensure('/ip/dhcp-server/network', 'comment', self::provisioningComment('portal DHCP network'), ['address' => $portal['network_cidr'], 'gateway' => explode('/', $portal['gateway_cidr'])[0], 'dns-server' => implode(',', $plan['dns_servers']), 'comment' => self::provisioningComment('portal DHCP network')], 'portal_network');
                $ensure('/ip/hotspot/profile', 'name', $names['portal_profile'], ['name' => $names['portal_profile'], 'hotspot-address' => explode('/', $portal['gateway_cidr'])[0], 'login-by' => 'http-pap'], 'portal_profile');
                $ensure('/ip/hotspot', 'name', $names['portal_hotspot'], ['name' => $names['portal_hotspot'], 'interface' => $names['portal_vlan'], 'profile' => $names['portal_profile'], 'disabled' => 'no', 'comment' => self::provisioningComment('isolated captive portal')], 'portal_hotspot');
            }

            return ['success' => true, 'message' => 'Every SolarNet provisioning resource was created and individually verified.', 'data' => ['created' => $created]];
        } catch (Throwable $e) {
            Log::warning('Direct MikroTik provisioning failed', ['router_id' => $router->id, 'created' => $created, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'RouterOS rejected a provisioning operation: ' . $e->getMessage(), 'data' => ['created' => $created]];
        }
    }

    /** Remove only resources confirmed as created by the current direct apply attempt. */
    public function removeCleanProvisioningResources(Router $router, array $plan, array $created): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $names = $plan['resource_names'];
            $definitions = [
                'portal_hotspot' => ['/ip/hotspot', 'name', $names['portal_hotspot'] ?? ''],
                'portal_profile' => ['/ip/hotspot/profile', 'name', $names['portal_profile'] ?? ''],
                'portal_dhcp' => ['/ip/dhcp-server', 'name', $names['portal_dhcp'] ?? ''],
                'portal_network' => ['/ip/dhcp-server/network', 'comment', self::provisioningComment('portal DHCP network')],
                'portal_pool' => ['/ip/pool', 'name', $names['portal_pool'] ?? ''],
                'portal_gateway' => ['/ip/address', 'comment', self::provisioningComment('portal gateway')],
                'portal_vlan' => ['/interface/vlan', 'name', $names['portal_vlan'] ?? ''],
                'customer_dhcp' => ['/ip/dhcp-server', 'name', $names['customer_dhcp'] ?? ''],
                'customer_network' => ['/ip/dhcp-server/network', 'comment', self::provisioningComment('customer DHCP network')],
                'customer_pool' => ['/ip/pool', 'name', $names['customer_pool'] ?? ''],
                'customer_gateway' => ['/ip/address', 'comment', self::provisioningComment('customer gateway')],
                'customer_vlan' => ['/interface/vlan', 'name', $names['customer_vlan'] ?? ''],
                'customer_nat' => ['/ip/firewall/nat', 'comment', self::provisioningComment('customer NAT')],
            ];
            foreach (array_reverse($created) as $key) {
                if (!isset($definitions[$key])) continue;
                [$menu, $field, $value] = $definitions[$key];
                if ($value === '') continue;
                $rows = $client->query((new Query($menu . '/print'))->where($field, $value))->read();
                foreach ($rows as $row) if (!empty($row['.id'])) $client->query((new Query($menu . '/remove'))->equal('.id', $row['.id']))->read();
            }
            return ['success' => true, 'message' => 'Only resources created by this provisioning attempt were removed.'];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Direct rollback failed: ' . $e->getMessage()];
        }
    }

    private static function provisioningComment(string $resource): string
    {
        return 'SolarNet Provisioning: ' . $resource;
    }

    /**
     * A basic Internet uplink needs source NAT. Accept only an enabled,
     * unmodified source-NAT masquerade rule here; destination NAT, port
     * forwarding, protocol/port matching, address translation, and mangle
     * remain production configuration and keep the router out of this wizard.
     */
    private static function isBaselineMasqueradeNat(array $rule): bool
    {
        if (($rule['disabled'] ?? 'false') === 'true') return false;
        if (($rule['chain'] ?? '') !== 'srcnat' || ($rule['action'] ?? '') !== 'masquerade') return false;

        foreach (['protocol', 'src-port', 'dst-port', 'src-address', 'dst-address', 'in-interface', 'in-interface-list', 'to-addresses', 'to-ports'] as $field) {
            if (!empty($rule[$field])) return false;
        }

        return true;
    }

    /** Preserve a narrow RouterOS API allow rule that keeps the verified app connection reachable. */
    private static function isBaselineApiFirewallRule(array $rule, array $apiPorts): bool
    {
        if (($rule['disabled'] ?? 'false') === 'true') return false;
        if (($rule['chain'] ?? '') !== 'input' || ($rule['action'] ?? '') !== 'accept' || strtolower((string) ($rule['protocol'] ?? '')) !== 'tcp') return false;

        return in_array((string) ($rule['dst-port'] ?? ''), $apiPorts, true);
    }

    /** Preserve an administrator input allow only when it is bound to a real point-to-point VPN interface. */
    private static function isBaselineVpnManagementRule(array $rule, array $interfaces): bool
    {
        if (($rule['disabled'] ?? 'false') === 'true') return false;
        if (($rule['chain'] ?? '') !== 'input' || ($rule['action'] ?? '') !== 'accept') return false;
        $interfaceName = (string) ($rule['in-interface'] ?? '');
        if ($interfaceName === '' || !empty($rule['in-interface-list'])) return false;

        $interface = null;
        foreach ($interfaces as $candidate) {
            if (($candidate['name'] ?? '') === $interfaceName) {
                $interface = $candidate;
                break;
            }
        }
        if (!is_array($interface) || ($interface['disabled'] ?? 'false') === 'true') return false;
        $type = strtolower((string) ($interface['type'] ?? ''));
        if (!in_array($type, ['sstp-in', 'sstp-out', 'ovpn-in', 'ovpn-out', 'l2tp-in', 'l2tp-out', 'pptp-in', 'pptp-out', 'ipip-tunnel', 'gre-tunnel'], true)) return false;

        foreach (['out-interface', 'out-interface-list', 'src-address', 'dst-address', 'src-address-list', 'dst-address-list', 'src-port', 'dst-port', 'jump-target', 'to-addresses', 'to-ports'] as $field) {
            if (!empty($rule[$field])) return false;
        }

        return true;
    }

    /** Accept only the five exact address-list based rules owned by SolarNet Billing. */
    private static function isBaselineBillingFirewallRule(array $rule): bool
    {
        if (($rule['disabled'] ?? 'false') === 'true' || ($rule['chain'] ?? '') !== 'forward') return false;
        $comment = (string) ($rule['comment'] ?? '');
        $definitions = [
            self::BILLING_RULE_PREFIX . ' allow temporary payment checkout' => ['accept', self::PAYMENT_SESSION_ADDRESS_LIST, '', ''],
            self::BILLING_RULE_PREFIX . ' block internet' => ['drop', self::SUSPENDED_ADDRESS_LIST, '', ''],
            self::BILLING_RULE_PREFIX . ' allow DNS TCP' => ['accept', self::SUSPENDED_ADDRESS_LIST, 'tcp', '53'],
            self::BILLING_RULE_PREFIX . ' allow DNS UDP' => ['accept', self::SUSPENDED_ADDRESS_LIST, 'udp', '53'],
            self::BILLING_RULE_PREFIX . ' allow payment portal' => ['accept', self::SUSPENDED_ADDRESS_LIST, 'tcp', '80,443'],
        ];
        if (!isset($definitions[$comment])) return false;
        [$action, $sourceList, $protocol, $port] = $definitions[$comment];
        if (($rule['action'] ?? '') !== $action || ($rule['src-address-list'] ?? '') !== $sourceList) return false;
        if ((string) ($rule['protocol'] ?? '') !== $protocol || (string) ($rule['dst-port'] ?? '') !== $port) return false;
        if ($comment === self::BILLING_RULE_PREFIX . ' allow payment portal' && ($rule['dst-address-list'] ?? '') !== self::PAYMENT_PORTAL_ADDRESS_LIST) return false;
        return true;
    }

    /** One coherent private bridge DHCP server/pool/network can be preserved regardless of administrator naming. */
    private static function isFactoryDhcpBaseline(array $servers, array $pools, array $networks, array $bridges): bool
    {
        $enabledServers = array_values(array_filter($servers, fn (array $server) => ($server['disabled'] ?? 'false') !== 'true'));
        if (count($enabledServers) !== 1) return false;
        $server = $enabledServers[0];
        $interfaceName = (string) ($server['interface'] ?? '');
        $poolName = (string) ($server['address-pool'] ?? '');
        if ($interfaceName === '' || $poolName === '' || $poolName === 'static-only') return false;

        $bridge = array_values(array_filter($bridges, fn (array $candidate) => ($candidate['name'] ?? '') === $interfaceName))[0] ?? null;
        $pool = array_values(array_filter($pools, fn (array $candidate) => ($candidate['name'] ?? '') === $poolName))[0] ?? null;
        if (!is_array($bridge) || !is_array($pool) || blank($pool['ranges'] ?? null)) return false;

        $validNetworks = array_values(array_filter($networks, function (array $network): bool {
            $gateway = (string) ($network['gateway'] ?? '');
            return filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false
                && preg_match('/^\d{1,3}(?:\.\d{1,3}){3}\/\d{1,2}$/', (string) ($network['address'] ?? '')) === 1;
        }));

        return count($validNetworks) === 1;
    }

    /** Dormant pools/networks do not serve clients and are safe to preserve unchanged. */
    private static function hasBlockingDhcpConfiguration(array $servers, bool $coherentBaseline): bool
    {
        $enabledServers = array_filter($servers, fn (array $server) => ($server['disabled'] ?? 'false') !== 'true');
        return $enabledServers !== [] && !$coherentBaseline;
    }

    private function containsSolarNetMarker(array $rows): bool
    {
        foreach ($rows as $row) {
            foreach (['name', 'comment', 'list'] as $field) {
                if (str_contains(strtolower((string) ($row[$field] ?? '')), 'solarnet')) return true;
            }
        }
        return false;
    }

    /**
     * Read and summarise the RouterOS configuration required for a QoS safety
     * decision. This method is deliberately read-only; it never calls add,
     * set, remove, move, or disable on the router.
     */
    public function qosInspection(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $readErrors = [];
            $read = function (string $path) use ($client, &$readErrors): array {
                try {
                    return $client->query(new Query($path))->read();
                } catch (Throwable $e) {
                    $readErrors[] = ['path' => $path, 'message' => $e->getMessage()];
                    return [];
                }
            };

            // All calls below mirror the safe-inspection list in the QoS UI.
            $resource = $read('/system/resource/print')[0] ?? [];
            $packages = $read('/system/package/print');
            $interfaces = $read('/interface/print');
            $bridges = $read('/interface/bridge/print');
            $vlans = $read('/interface/vlan/print');
            $addresses = $read('/ip/address/print');
            $routes = $read('/ip/route/print');
            $filters = $read('/ip/firewall/filter/print');
            $mangles = $read('/ip/firewall/mangle/print');
            $nat = $read('/ip/firewall/nat/print');
            // Do not enumerate the live firewall connection table here. It is
            // not a QoS safety prerequisite and can be very large on a
            // concentrator, causing an otherwise read-only inspection to time
            // out. Connection state belongs in an opt-in diagnostic instead.
            $dhcpServers = $read('/ip/dhcp-server/print');
            $dhcpLeases = $read('/ip/dhcp-server/lease/print');
            $simpleQueues = $read('/queue/simple/print');
            $queueTrees = $read('/queue/tree/print');
            $queueTypes = $read('/queue/type/print');
            $routingRules = $read('/routing/rule/print');
            $ethernet = $read('/interface/ethernet/print');
            $wireguard = $read('/interface/wireguard/print');

            $interfaceNames = array_values(array_filter(array_map(fn (array $item) => $item['name'] ?? null, $interfaces)));
            $dhcpInterfaces = array_values(array_unique(array_filter(array_map(fn (array $item) => $item['interface'] ?? null, $dhcpServers))));
            $bridgeNames = array_values(array_filter(array_map(fn (array $item) => $item['name'] ?? null, $bridges)));
            $vlanNames = array_values(array_filter(array_map(fn (array $item) => $item['name'] ?? null, $vlans)));
            $defaultRoutes = array_values(array_filter($routes, fn (array $route) => in_array((string) ($route['dst-address'] ?? ''), ['0.0.0.0/0', '::/0'], true) && ($route['disabled'] ?? 'false') !== 'true'));
            $fastTrackRules = array_values(array_filter($filters, fn (array $rule) => ($rule['disabled'] ?? 'false') !== 'true' && ($rule['action'] ?? '') === 'fasttrack-connection'));
            $billingQueues = array_values(array_filter($simpleQueues, fn (array $queue) => str_starts_with((string) ($queue['name'] ?? ''), 'customer-')));
            $managedQosTrees = array_values(array_filter($queueTrees, fn (array $queue) => str_starts_with((string) ($queue['comment'] ?? ''), self::QOS_OWNER_PREFIX)));

            $queueCapabilities = [
                'cake' => [],
                'fq_codel' => [],
                'pcq' => [],
            ];
            foreach ($queueTypes as $queueType) {
                $name = (string) ($queueType['name'] ?? '');
                $kind = (string) ($queueType['kind'] ?? '');
                $haystack = strtolower($name . ' ' . $kind);
                if (str_contains($haystack, 'cake')) $queueCapabilities['cake'][] = $name;
                if (str_contains($haystack, 'fq-codel') || str_contains($haystack, 'fqcodel')) $queueCapabilities['fq_codel'][] = $name;
                if (str_contains($haystack, 'pcq')) $queueCapabilities['pcq'][] = $name;
            }

            $candidateGateways = [];
            foreach ($defaultRoutes as $route) {
                $gateway = (string) ($route['gateway'] ?? $route['immediate-gw'] ?? '');
                $interface = null;
                if (preg_match('/%([^\s,]+)/', $gateway, $match) === 1) $interface = $match[1];
                if ($interface === null && in_array($gateway, $interfaceNames, true)) $interface = $gateway;
                $candidateGateways[] = [
                    'gateway' => $gateway ?: null,
                    'interface' => $interface,
                    'distance' => $route['distance'] ?? null,
                    'routing_table' => $route['routing-table'] ?? 'main',
                ];
            }

            $clientSubnets = [];
            foreach ($addresses as $address) {
                if (in_array($address['interface'] ?? null, $dhcpInterfaces, true) && isset($address['address'])) {
                    $clientSubnets[] = ['interface' => $address['interface'], 'address' => $address['address']];
                }
            }

            $warnings = [];
            if ($fastTrackRules !== []) $warnings[] = 'FastTrack is enabled. SolarNet QoS deployment is blocked until an administrator intentionally resolves FastTrack outside this automatic workflow.';
            if (count($defaultRoutes) > 1) $warnings[] = 'Multiple active default routes were detected. A QoS profile must be explicitly scoped to one verified WAN path.';
            if (count($dhcpInterfaces) > 1 || count($vlanNames) > 1) $warnings[] = 'Multiple client-facing DHCP/VLAN interfaces were detected. A single global download queue would be unsafe, so the preview requires explicit parent interfaces.';
            if ($managedQosTrees !== []) $warnings[] = 'SolarNet-owned QoS queue trees already exist. The system will not stack another QoS deployment on top of them.';
            if ($queueCapabilities['fq_codel'] === [] && $queueCapabilities['pcq'] === []) $warnings[] = 'No FQ-CoDel or PCQ queue type was detected. QoS deployment is blocked rather than guessing a queue type.';

            return [
                'success' => true,
                'data' => [
                    'routeros_version' => $resource['version'] ?? null,
                    'board_name' => $resource['board-name'] ?? null,
                    'architecture' => $resource['architecture-name'] ?? null,
                    'cpu_load' => (int) ($resource['cpu-load'] ?? 0),
                    'free_memory' => (int) ($resource['free-memory'] ?? 0),
                    'total_memory' => (int) ($resource['total-memory'] ?? 0),
                    'uptime' => $resource['uptime'] ?? null,
                    'packages' => array_map(fn (array $package) => ['name' => $package['name'] ?? null, 'version' => $package['version'] ?? null, 'disabled' => ($package['disabled'] ?? 'false') === 'true'], $packages),
                    'interfaces' => array_map(fn (array $interface) => ['name' => $interface['name'] ?? null, 'type' => $interface['type'] ?? null, 'running' => ($interface['running'] ?? 'false') === 'true', 'disabled' => ($interface['disabled'] ?? 'false') === 'true'], $interfaces),
                    'bridge_interfaces' => $bridgeNames,
                    'vlan_interfaces' => $vlanNames,
                    'client_interfaces' => $dhcpInterfaces,
                    'client_subnets' => $clientSubnets,
                    'wan_candidates' => $candidateGateways,
                    'multi_wan_detected' => count($defaultRoutes) > 1,
                    'fasttrack' => ['enabled' => $fastTrackRules !== [], 'count' => count($fastTrackRules)],
                    'existing_queues' => [
                        'simple_total' => count($simpleQueues),
                        'billing_customer_queues' => count($billingQueues),
                        'other_simple_queues' => count($simpleQueues) - count($billingQueues),
                        'queue_tree_total' => count($queueTrees),
                        'solarnet_qos_trees' => count($managedQosTrees),
                    ],
                    'queue_capabilities' => $queueCapabilities,
                    'mangle_rule_count' => count($mangles),
                    'firewall_filter_count' => count($filters),
                    'firewall_nat_count' => count($nat),
                    'routing_rule_count' => count($routingRules),
                    'active_connections' => null,
                    'dhcp_lease_count' => count($dhcpLeases),
                    'ethernet_interface_count' => count($ethernet),
                    'wireguard_interface_count' => count($wireguard),
                    'warnings' => $warnings,
                    'inspection_errors' => $readErrors,
                    'inspected_at' => now()->toIso8601String(),
                ],
            ];
        } catch (Throwable $e) {
            Log::warning('Router QoS inspection failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'QoS inspection failed: ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Read one SolarNet-owned customer Simple Queue. Ownership is deliberately
     * strict: the generated queue name, router relation, and exact /32 target
     * must all agree. An administrator-created queue is never a candidate.
     */
    public function readManagedCustomerQueue(Router $router, Customer $customer): array
    {
        if ($customer->router_id !== $router->id || !$customer->ip_address) {
            return ['success' => false, 'message' => 'Customer is not assigned to this router with a current IP address.'];
        }

        $queueName = 'customer-' . $customer->id;
        try {
            $client = new Client($this->makeConfig($router));
            $rows = $client->query((new Query('/queue/simple/print'))->where('name', $queueName))->read();
            $queue = $rows[0] ?? null;
            if (!$queue) return ['success' => false, 'message' => "SolarNet-managed queue {$queueName} was not found."];
            if (!$this->isExactCustomerQueueTarget((string) ($queue['target'] ?? ''), $customer->ip_address)) {
                return ['success' => false, 'message' => 'The SolarNet queue target no longer exactly matches this customer IP. No QoS change is allowed.'];
            }

            return ['success' => true, 'data' => $this->simpleQueueSnapshot($queue)];
        } catch (Throwable $e) {
            Log::warning('Could not read managed customer queue for Safe QoS', ['router_id' => $router->id, 'customer_id' => $customer->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not read the SolarNet-managed customer queue: ' . $e->getMessage()];
        }
    }

    /** Apply only an existing SolarNet queue's queue discipline. Limits and topology stay untouched. */
    public function applySafeQueueType(Router $router, Customer $customer, array $before, string $queueType): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $current = $this->findSimpleQueueFromClient($client, (string) ($before['name'] ?? ''));
            if (!$current || !$this->queueStillMatchesSnapshot($current, $before) || !$this->isExactCustomerQueueTarget((string) ($current['target'] ?? ''), (string) $customer->ip_address)) {
                return ['success' => false, 'message' => 'The managed queue changed after preview or no longer matches the client IP. Safe QoS was not applied.'];
            }

            $response = $client->query(
                (new Query('/queue/simple/set'))
                    ->equal('.id', $current['.id'])
                    ->equal('queue', $queueType)
            )->read();
            if ($this->routerOsTrap($response)) return ['success' => false, 'message' => 'RouterOS rejected the Safe QoS queue-type update: ' . $this->routerOsTrap($response)];

            $saved = $this->findSimpleQueueFromClient($client, (string) $before['name']);
            if (!$saved || !$this->queueStillMatchesSnapshot($saved, $before) || (string) ($saved['queue'] ?? '') !== $queueType) {
                return ['success' => false, 'message' => 'RouterOS did not verify the Safe QoS queue update. Existing queue limits were left unchanged.'];
            }

            return ['success' => true, 'data' => $this->simpleQueueSnapshot($saved), 'message' => 'Safe QoS queue discipline was applied and the original limit, target, parent, packet mark, priority, and comment were verified unchanged.'];
        } catch (Throwable $e) {
            Log::warning('Safe QoS queue update failed', ['router_id' => $router->id, 'customer_id' => $customer->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Safe QoS queue update failed without changing a global router setting: ' . $e->getMessage()];
        }
    }

    /** Restore only fields captured from one managed customer queue before its Safe QoS test. */
    public function restoreManagedCustomerQueue(Router $router, Customer $customer, array $before): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $current = $this->findSimpleQueueFromClient($client, (string) ($before['name'] ?? ''));
            if (!$current || !$this->isExactCustomerQueueTarget((string) ($current['target'] ?? ''), (string) $customer->ip_address)) {
                return ['success' => false, 'message' => 'The managed queue cannot be safely restored because its exact client target no longer matches.'];
            }

            $restoreFields = ['target', 'max-limit', 'queue', 'parent', 'packet-marks', 'priority', 'limit-at', 'burst-limit', 'burst-threshold', 'burst-time', 'bucket-size', 'disabled', 'comment'];
            $query = (new Query('/queue/simple/set'))->equal('.id', $current['.id']);
            foreach ($restoreFields as $field) {
                if (array_key_exists($field, $before)) $query->equal($field, (string) $before[$field]);
            }
            $response = $client->query($query)->read();
            if ($this->routerOsTrap($response)) return ['success' => false, 'message' => 'RouterOS rejected the Safe QoS rollback: ' . $this->routerOsTrap($response)];

            $saved = $this->findSimpleQueueFromClient($client, (string) $before['name']);
            if (!$saved || !$this->queueMatchesRestoreSnapshot($saved, $before)) {
                return ['success' => false, 'message' => 'RouterOS did not verify restoration of the original managed queue configuration.'];
            }

            return ['success' => true, 'data' => $this->simpleQueueSnapshot($saved), 'message' => 'The original SolarNet-managed customer queue configuration was restored and verified.'];
        } catch (Throwable $e) {
            Log::warning('Safe QoS queue rollback failed', ['router_id' => $router->id, 'customer_id' => $customer->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not restore the Safe QoS customer queue: ' . $e->getMessage()];
        }
    }

    private function findSimpleQueueFromClient(Client $client, string $name): ?array
    {
        if ($name === '') return null;
        return $client->query((new Query('/queue/simple/print'))->where('name', $name))->read()[0] ?? null;
    }

    private function simpleQueueSnapshot(array $queue): array
    {
        $fields = ['name', 'target', 'max-limit', 'queue', 'parent', 'packet-marks', 'priority', 'limit-at', 'burst-limit', 'burst-threshold', 'burst-time', 'bucket-size', 'disabled', 'comment', 'rate', 'dropped', 'bytes'];
        $snapshot = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $queue)) $snapshot[$field] = (string) $queue[$field];
        }
        return $snapshot;
    }

    private function isExactCustomerQueueTarget(string $target, string $ipAddress): bool
    {
        $targets = array_values(array_filter(array_map('trim', explode(',', $target))));
        return count($targets) === 1 && in_array($targets[0], [$ipAddress, $ipAddress . '/32'], true);
    }

    /** Fields that must remain untouched when only the queue discipline changes. */
    private function queueStillMatchesSnapshot(array $queue, array $before): bool
    {
        foreach (['name', 'target', 'max-limit', 'parent', 'packet-marks', 'priority', 'limit-at', 'burst-limit', 'burst-threshold', 'burst-time', 'bucket-size', 'disabled', 'comment'] as $field) {
            if (array_key_exists($field, $before) && (string) ($queue[$field] ?? '') !== (string) $before[$field]) return false;
        }
        return true;
    }

    private function queueMatchesRestoreSnapshot(array $queue, array $before): bool
    {
        foreach (['target', 'max-limit', 'queue', 'parent', 'packet-marks', 'priority', 'limit-at', 'burst-limit', 'burst-threshold', 'burst-time', 'bucket-size', 'disabled', 'comment'] as $field) {
            if (array_key_exists($field, $before) && (string) ($queue[$field] ?? '') !== (string) $before[$field]) return false;
        }
        return true;
    }

    private function routerOsTrap(array $response): ?string
    {
        $trap = collect($response)->first(fn (array $row) => isset($row['!trap']) || isset($row['message']));
        return $trap['message'] ?? null;
    }

    /** Create a RouterOS binary backup and verify that the router reports the file. */
    public function createQosBackup(Router $router, string $backupName): array
    {
        $safeName = preg_replace('/[^A-Za-z0-9_-]/', '-', $backupName) ?: 'solarnet-qos-backup';

        try {
            $client = new Client($this->makeConfig($router));
            $client->query((new Query('/system/backup/save'))->equal('name', $safeName))->read();
            usleep(500000);
            $files = $client->query(new Query('/file/print'))->read();
            $file = collect($files)->first(fn (array $item) => in_array((string) ($item['name'] ?? ''), [$safeName, $safeName . '.backup'], true));
            if (!$file) return ['success' => false, 'message' => 'RouterOS did not confirm the QoS backup file. Deployment was blocked.'];

            return [
                'success' => true,
                'backup_file' => $file['name'],
                'message' => 'RouterOS configuration backup created and verified.',
            ];
        } catch (Throwable $e) {
            Log::warning('Router QoS backup failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'QoS backup failed. Deployment was blocked: ' . $e->getMessage()];
        }
    }

    /** Add the two SolarNet-owned interface queue trees, preserving every existing queue/rule. */
    public function applyManagedQosTrees(Router $router, string $deploymentId, array $configuration): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $existing = $client->query(new Query('/queue/tree/print'))->read();
            if (array_filter($existing, fn (array $queue) => str_starts_with((string) ($queue['comment'] ?? ''), self::QOS_OWNER_PREFIX))) {
                return ['success' => false, 'message' => 'SolarNet QoS queue trees already exist. No second QoS deployment was created.'];
            }

            $shortId = substr($deploymentId, 0, 8);
            $definitions = [
                ['name' => self::QOS_OWNER_PREFIX . ':' . $shortId . ':download', 'parent' => $configuration['download_parent'], 'max_limit' => $configuration['download_limit'], 'comment' => self::QOS_OWNER_PREFIX . ' deployment ' . $shortId . ' download shaping'],
                ['name' => self::QOS_OWNER_PREFIX . ':' . $shortId . ':upload', 'parent' => $configuration['upload_parent'], 'max_limit' => $configuration['upload_limit'], 'comment' => self::QOS_OWNER_PREFIX . ' deployment ' . $shortId . ' upload shaping'],
            ];
            $created = [];

            try {
                foreach ($definitions as $definition) {
                    $client->query(
                        (new Query('/queue/tree/add'))
                            ->equal('name', $definition['name'])
                            ->equal('parent', $definition['parent'])
                            ->equal('max-limit', $definition['max_limit'])
                            ->equal('queue', $configuration['queue_type'])
                            ->equal('comment', $definition['comment'])
                    )->read();
                    $created[] = $definition;
                }
            } catch (Throwable $applyError) {
                $this->removeManagedQosTreesFromClient($client);
                throw $applyError;
            }

            $verification = $this->verifyManagedQosTreesFromClient($client, $created, $configuration['queue_type']);
            if (!$verification['success']) {
                $this->removeManagedQosTreesFromClient($client);
                return ['success' => false, 'message' => 'QoS verification failed; the SolarNet-owned QoS trees were removed.', 'verification' => $verification];
            }

            return ['success' => true, 'message' => 'SolarNet QoS queue trees applied and verified.', 'verification' => $verification];
        } catch (Throwable $e) {
            Log::warning('QoS queue tree deployment failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'QoS deployment failed without changing existing customer queues: ' . $e->getMessage()];
        }
    }

    /** Remove only queue trees whose comment is owned by SolarNet QoS. */
    public function removeManagedQosTrees(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $removed = $this->removeManagedQosTreesFromClient($client);
            return ['success' => true, 'message' => "Removed {$removed} SolarNet-owned QoS queue tree(s). Existing customer queues were preserved.", 'removed' => $removed];
        } catch (Throwable $e) {
            Log::warning('QoS tree removal failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Could not remove only SolarNet QoS trees: ' . $e->getMessage()];
        }
    }

    /** Actual RouterOS metrics only; latency/loss are reported only after an explicit ping test. */
    public function qosMetrics(Router $router): array
    {
        $monitoring = $this->monitoringSnapshot($router);
        if (!$monitoring['success']) return $monitoring;

        try {
            $client = new Client($this->makeConfig($router));
            $trees = $client->query(new Query('/queue/tree/print'))->read();
            $drops = 0;
            foreach ($trees as $tree) {
                foreach (explode('/', (string) ($tree['dropped'] ?? '0/0')) as $part) $drops += (int) trim($part);
            }

            return [
                'success' => true,
                'data' => array_merge($monitoring['data'], [
                    // Avoid polling all firewall connections every five
                    // seconds. It neither influences the QoS plan nor its
                    // safe verification, and may overload busy routers.
                    'active_connections' => null,
                    'queue_tree_count' => count($trees),
                    'queue_drops' => $drops,
                    'latency_ms' => null,
                    'packet_loss_percent' => null,
                    'latency_note' => 'Run Test QoS to collect a real ping latency and packet-loss sample.',
                    'measured_at' => now()->toIso8601String(),
                ]),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'QoS metrics read failed: ' . $e->getMessage(), 'data' => null];
        }
    }

    /** Run a bounded RouterOS ping for a real latency/loss snapshot. */
    public function qosPingTest(Router $router, string $target): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $rows = $client->query((new Query('/ping'))->equal('address', $target)->equal('count', '5'))->read();
            $times = [];
            foreach ($rows as $row) {
                if (preg_match('/([0-9.]+)ms/', (string) ($row['time'] ?? ''), $match) === 1) $times[] = (float) $match[1];
            }
            $sent = max(5, count($rows));
            $received = count($times);
            return [
                'success' => true,
                'data' => [
                    'target' => $target,
                    'sent' => $sent,
                    'received' => $received,
                    'packet_loss_percent' => round((($sent - $received) / $sent) * 100, 2),
                    'latency_ms' => $times === [] ? null : round(array_sum($times) / count($times), 2),
                    'minimum_latency_ms' => $times === [] ? null : min($times),
                    'maximum_latency_ms' => $times === [] ? null : max($times),
                    'tested_at' => now()->toIso8601String(),
                ],
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'QoS ping test failed: ' . $e->getMessage(), 'data' => null];
        }
    }

    private function removeManagedQosTreesFromClient(Client $client): int
    {
        $trees = $client->query(new Query('/queue/tree/print'))->read();
        $managed = array_values(array_filter($trees, fn (array $tree) => str_starts_with((string) ($tree['comment'] ?? ''), self::QOS_OWNER_PREFIX)));
        foreach ($managed as $tree) {
            if (!empty($tree['.id'])) $client->query((new Query('/queue/tree/remove'))->equal('.id', $tree['.id']))->read();
        }
        return count($managed);
    }

    private function verifyManagedQosTreesFromClient(Client $client, array $definitions, string $queueType): array
    {
        $trees = $client->query(new Query('/queue/tree/print'))->read();
        $verified = [];
        foreach ($definitions as $definition) {
            $tree = collect($trees)->first(fn (array $item) => ($item['name'] ?? null) === $definition['name']);
            if (!$tree || ($tree['parent'] ?? null) !== $definition['parent'] || ($tree['queue'] ?? null) !== $queueType) {
                return ['success' => false, 'message' => 'A managed QoS queue tree was absent or did not verify.', 'verified' => $verified];
            }
            $verified[] = ['name' => $tree['name'], 'parent' => $tree['parent'], 'queue' => $tree['queue'], 'max_limit' => $tree['max-limit'] ?? null];
        }
        return ['success' => true, 'verified' => $verified];
    }

    /**
     * Read a narrow RouterOS security inventory for an administrator review.
     *
     * Every command in this method is a print command. It deliberately avoids
     * connection-table scans, packet capture, credentials, WireGuard peers,
     * certificates, DHCP data, and any RouterOS write action.
     */
    public function securityBaselineInspection(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router, 3, 12));
            $read = fn (string $path): array => $client->query(new Query($path))->read();

            $inventory = [
                'filters' => $read('/ip/firewall/filter/print'),
                'nat' => $read('/ip/firewall/nat/print'),
                'address_lists' => $read('/ip/firewall/address-list/print'),
                'services' => $read('/ip/service/print'),
                'interface_lists' => $read('/interface/list/print'),
                'interface_list_members' => $read('/interface/list/member/print'),
            ];

            // IPv6 menus can be disabled or unavailable on an older RouterOS
            // build. That is reported in the baseline, not treated as an IPv4
            // firewall failure or as a reason to modify the router.
            $optionalErrors = [];
            foreach ([
                'wireguard' => '/interface/wireguard/print',
                'ipv6_filters' => '/ipv6/firewall/filter/print',
                'ipv6_addresses' => '/ipv6/address/print',
            ] as $key => $path) {
                try {
                    $inventory[$key] = $read($path);
                } catch (Throwable $e) {
                    $inventory[$key] = [];
                    $optionalErrors[] = $path;
                }
            }

            $analysis = (new RouterSecurityBaselineAnalyzer())->analyze($inventory);
            $analysis['router'] = [
                'id' => $router->id,
                'name' => $router->name,
                'host' => $router->host,
                'inspected_at' => now()->toIso8601String(),
            ];
            $analysis['inspection_warnings'] = $optionalErrors === []
                ? []
                : ['Some optional RouterOS menus were unavailable and were not evaluated: ' . implode(', ', $optionalErrors) . '.'];

            return [
                'success' => true,
                'message' => "Read-only security baseline completed for {$router->name}. No RouterOS configuration was changed.",
                'data' => $analysis,
            ];
        } catch (Throwable $e) {
            Log::warning('Router security baseline read failed', [
                'router_id' => $router->id,
                'host' => $router->host,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Could not read the RouterOS security baseline. No firewall, NAT, DHCP, queue, VPN, DNS, or billing setting was changed.',
                'data' => null,
            ];
        }
    }

    /**
     * Compare the router's current connection table with a supplied set of
     * IPv4 indicators. This is intentionally read-only: it never changes
     * RouterOS firewall state and is run only from a user-triggered scan.
     *
     * @param array<string, true> $indicators
     */
    public function threatFeedConnections(Router $router, array $indicators): array
    {
        try {
            $limit = min(10000, max(1, (int) config('threat-monitor.connection_limit', 2000)));
            $socketTimeout = min(30, max(5, (int) config('threat-monitor.connection_socket_timeout', 15)));
            $client = new Client($this->makeConfig($router, 3, $socketTimeout));

            // Do not retrieve the entire, detailed connection table and then
            // slice it in PHP. On a concentrator that can mean tens of
            // thousands of records and a socket timeout. RouterOS returns
            // only the two fields needed for the read-only feed comparison.
            // The RouterOS PHP client's count option is the actual response
            // cap; its iterator alone still waits for the complete reply.
            $query = (new Query('/ip/firewall/connection/print'))
                ->equal('.proplist', 'src-address,dst-address');
            $connections = $client->query($query)->read(true, ['count' => $limit]);
            $matches = [];
            $connectionsChecked = count($connections);

            foreach ($connections as $connection) {
                if (!is_array($connection)) continue;
                foreach (['source' => $connection['src-address'] ?? null, 'destination' => $connection['dst-address'] ?? null] as $direction => $address) {
                    $ip = $this->ipv4FromRouterAddress($address);
                    if ($ip === null || !isset($indicators[$ip])) continue;

                    $matches[$ip]['remote_ip'] = $ip;
                    $matches[$ip]['directions'][$direction] = true;
                }
            }

            return [
                'success' => true,
                'connections_checked' => $connectionsChecked,
                'scan_limited' => $connectionsChecked >= $limit,
                'connection_limit' => $limit,
                'matches' => array_values(array_map(fn (array $match) => [
                    'remote_ip' => $match['remote_ip'],
                    'directions' => array_keys($match['directions']),
                ], $matches)),
            ];
        } catch (Throwable $e) {
            Log::warning('Router threat-feed connection read failed', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return [
                'success' => false,
                'message' => 'Could not read the bounded active RouterOS connection sample: ' . $e->getMessage() . '. No firewall or RouterOS configuration was changed.',
                'matches' => [],
            ];
        }
    }

    /**
     * Add one reviewed indicator to a SolarNet-owned address list and ensure
     * the two dedicated forward-chain drop rules exist. No unrelated list or
     * firewall rule is changed. This is invoked only after administrator
     * approval of a pending threat observation.
     */
    public function blockReviewedThreat(Router $router, string $remoteIp, string $feedName): array
    {
        if (!filter_var($remoteIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['success' => false, 'message' => 'Only a valid IPv4 threat indicator can be blocked.'];
        }

        try {
            $client = new Client($this->makeConfig($router));
            $timeout = $this->reviewedThreatBlockTimeout();
            $timeoutSeconds = $this->reviewedThreatBlockTimeoutSeconds($timeout);
            $entries = $client->query(
                (new Query('/ip/firewall/address-list/print'))
                    ->where('list', self::THREAT_FEED_ADDRESS_LIST)
                    ->where('address', $remoteIp)
            )->read();

            if (empty($entries)) {
                $client->query(
                    (new Query('/ip/firewall/address-list/add'))
                        ->equal('list', self::THREAT_FEED_ADDRESS_LIST)
                        ->equal('address', $remoteIp)
                        ->equal('timeout', $timeout)
                        ->equal('comment', self::THREAT_FEED_RULE_PREFIX . ' — ' . $feedName)
                )->read();
            }

            $this->ensureThreatFeedBlockRules($client);

            return [
                'success' => true,
                'message' => "{$remoteIp} was added to the SolarNet threat-feed block list after manual approval. The new block expires after {$timeout}.",
                'address_list' => self::THREAT_FEED_ADDRESS_LIST,
                'timeout' => $timeout,
                'timeout_seconds' => $timeoutSeconds,
            ];
        } catch (Throwable $e) {
            Log::warning('Could not apply reviewed threat block', ['router_id' => $router->id, 'remote_ip' => $remoteIp, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'No threat block was applied: ' . $e->getMessage()];
        }
    }

    /**
     * RouterOS accepts rich duration strings, but this guard deliberately
     * limits the environment setting to a simple, auditable temporary period.
     * An invalid deployment value safely falls back to one day.
     */
    private function reviewedThreatBlockTimeout(): string
    {
        $timeout = strtolower(trim((string) config('threat-monitor.manual_block_timeout', '1d')));

        if (preg_match('/^(\d+)(s|m|h|d|w)$/', $timeout, $matches) !== 1) return '1d';
        $quantity = (int) $matches[1];
        if ($quantity < 1) return '1d';

        $multiplier = match ($matches[2]) {
            's' => 1,
            'm' => 60,
            'h' => 3_600,
            'd' => 86_400,
            'w' => 604_800,
        };

        return ($quantity * $multiplier) <= 604_800 ? $timeout : '1w';
    }

    private function reviewedThreatBlockTimeoutSeconds(string $timeout): int
    {
        if (preg_match('/^(\d+)(s|m|h|d|w)$/', $timeout, $matches) !== 1) return 86_400;
        $quantity = (int) $matches[1];
        if ($quantity < 1) return 86_400;

        $multiplier = match ($matches[2]) {
            's' => 1,
            'm' => 60,
            'h' => 3_600,
            'd' => 86_400,
            'w' => 604_800,
        };

        // The product keeps a confirmed block temporary by design. A longer
        // policy needs a fresh review rather than an accidental forever-ban.
        return min(604_800, $quantity * $multiplier);
    }

    private function ipv4FromRouterAddress(mixed $address): ?string
    {
        if (!is_string($address) || preg_match('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $address, $match) !== 1) return null;
        return filter_var($match[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $match[0] : null;
    }

    /**
     * The comments are the ownership boundary. Existing customer, VPN and
     * billing rules are neither moved nor edited. Both directions are blocked
     * because a C2 address can be seen as source or destination in RouterOS.
     */
    private function ensureThreatFeedBlockRules(Client $client): void
    {
        $definitions = [
            ['direction' => 'dst-address-list', 'comment' => self::THREAT_FEED_RULE_PREFIX . ' outbound'],
            ['direction' => 'src-address-list', 'comment' => self::THREAT_FEED_RULE_PREFIX . ' inbound'],
        ];

        foreach ($definitions as $position => $definition) {
            $rules = $client->query((new Query('/ip/firewall/filter/print'))->where('comment', $definition['comment']))->read();
            $ruleId = $rules[0]['.id'] ?? null;
            if ($ruleId === null) {
                $response = $client->query(
                    (new Query('/ip/firewall/filter/add'))
                        ->equal('chain', 'forward')
                        ->equal($definition['direction'], self::THREAT_FEED_ADDRESS_LIST)
                        ->equal('action', 'drop')
                        ->equal('comment', $definition['comment'])
                )->read();
                $ruleId = $response[0]['ret'] ?? null;
                if ($ruleId === null) {
                    $created = $client->query((new Query('/ip/firewall/filter/print'))->where('comment', $definition['comment']))->read();
                    $ruleId = $created[0]['.id'] ?? null;
                }
            }

            if ($ruleId === null) throw new \RuntimeException('Could not identify the SolarNet threat-feed firewall rule after creation.');

            $client->query(
                (new Query('/ip/firewall/filter/move'))
                    ->equal('numbers', $ruleId)
                    ->equal('destination', (string) $position)
            )->read();
        }
    }

    /**
     * Sync everything from the router — system status, queues, DHCP leases.
     * Persists snapshot counts into the routers row and returns per-item counts.
     */
    public function syncRouter(Router $router): array
    {
        $result = [
            'success'      => true,
            'message'      => '',
            'synced_items' => [
                'dhcp_leases' => 0,
                'queues'      => 0,
                'system'      => false,
            ],
            'errors'       => [],
        ];

        // 1) System / version — also functions as a live connectivity check
        $conn = $this->testConnection($router);
        if (!$conn['success']) {
            return [
                'success' => false,
                'message' => $conn['message'],
                'synced_items' => $result['synced_items'],
                'errors' => [$conn['message']],
            ];
        }
        $result['synced_items']['system'] = true;

        // 2) DHCP leases
        try {
            $leases = $this->getDhcpLeasesDetailed($router);
            if ($leases['success']) {
                $result['synced_items']['dhcp_leases'] = $leases['count'];
            } else {
                $result['errors'][] = 'dhcp_leases: ' . ($leases['message'] ?? 'failed');
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'dhcp_leases exception: ' . $e->getMessage();
        }

        // 3) Queues
        try {
            $queues = $this->getQueues($router);
            if ($queues['success']) {
                $result['synced_items']['queues'] = is_array($queues['data']) ? count($queues['data']) : 0;
            } else {
                $result['errors'][] = 'queues: ' . ($queues['message'] ?? 'failed');
            }
        } catch (Throwable $e) {
            $result['errors'][] = 'queues exception: ' . $e->getMessage();
        }

        // 4) Persist snapshot on the router record
        $router->update(['last_sync_at' => now()]);

        $result['message'] = sprintf(
            'Synced %d DHCP leases, %d queues from %s',
            $result['synced_items']['dhcp_leases'],
            $result['synced_items']['queues'],
            $router->name
        );
        if (!empty($result['errors'])) {
            $result['success'] = false;
        }

        return $result;
    }

    /**
     * Get DHCP leases from router
     * Placeholder for Phase 6
     * 
     * @param Router $router
     * @return array
     */
    public function getDhcpLeases(Router $router): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            $query = new Query('/ip/dhcp-server/lease/print');
            $leases = $client->query($query)->read();
            
            return [
                'success' => true,
                'data' => $leases,
            ];
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Add a simple queue for a customer
     * 
     * @param Router $router
     * @param array $queueData
     * @return array{success: bool, message: string, queue_id: string|null}
     */
    public function addQueue(Router $router, array $queueData): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            // Build queue parameters
            $params = [
                'name' => $queueData['name'],
                'target' => $queueData['target'], // IP address
                'max-limit' => $queueData['max_limit'], // e.g., "100M/50M"
                'comment' => $queueData['comment'] ?? '',
            ];
            
            // Add burst if provided
            if (!empty($queueData['burst_limit'])) {
                $params['burst-limit'] = $queueData['burst_limit'];
                $params['burst-threshold'] = $queueData['burst_threshold'];
                $params['burst-time'] = $queueData['burst_time'];
            }
            
            // Add priority if provided
            if (!empty($queueData['priority'])) {
                $params['priority'] = $queueData['priority'] . '/' . $queueData['priority'];
            }
            
            // Create the queue
            $query = (new Query('/queue/simple/add'));
            foreach ($params as $key => $value) {
                $query->equal($key, $value);
            }
            
            $response = $client->query($query)->read();
            
            // Get the ID of created queue
            $queueId = $response[0]['after']['ret'] ?? null;
            
            Log::info('Queue created on MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueData['name'],
                'target' => $queueData['target'],
                'queue_id' => $queueId,
            ]);
            
            return [
                'success' => true,
                'message' => 'Queue created successfully',
                'queue_id' => $queueId,
            ];
            
        } catch (Throwable $e) {
            Log::error('Failed to create queue on MikroTik', [
                'router' => $router->name,
                'error' => $e->getMessage(),
                'queue_data' => $queueData,
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to create queue: ' . $e->getMessage(),
                'queue_id' => null,
            ];
        }
    }

    /**
     * Update an existing queue
     * 
     * @param Router $router
     * @param string $queueName
     * @param array $updates
     * @return array{success: bool, message: string}
     */
    public function updateQueue(Router $router, string $queueName, array $updates): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            // Find the queue by name
            $query = (new Query('/queue/simple/print'))
                ->where('name', $queueName);
            $queues = $client->query($query)->read();
            
            if (empty($queues)) {
                return [
                    'success' => false,
                    'message' => 'Queue not found: ' . $queueName,
                ];
            }
            
            $queueId = $queues[0]['.id'];
            
            // Build update query
            $query = (new Query('/queue/simple/set'))
                ->equal('.id', $queueId);
            
            foreach ($updates as $key => $value) {
                $query->equal($key, $value);
            }
            
            $response = $client->query($query)->read();

            $trap = collect($response)->first(fn (array $row) => isset($row['!trap']) || isset($row['message']));
            if ($trap) {
                return [
                    'success' => false,
                    'message' => 'RouterOS rejected queue update: ' . ($trap['message'] ?? 'unknown error'),
                ];
            }

            // RouterOS API calls can complete without throwing when a setting
            // was not applied. Read the queue back so callers never report a
            // false success to the operator.
            $verify = (new Query('/queue/simple/print'))->where('name', $queueName);
            $saved = $client->query($verify)->read()[0] ?? null;
            $expectedLimit = $updates['max-limit'] ?? null;
            $savedLimit = $saved['max-limit'] ?? null;
            $expectedTarget = $updates['target'] ?? null;
            $savedTarget = $saved['target'] ?? null;
            $normalizeTarget = static fn (?string $target): string => (string) preg_replace('/\\/32$/', '', str_replace(' ', '', (string) $target));
            if (!$saved || ($expectedLimit && !$this->sameRateLimit($expectedLimit, $savedLimit))
                || ($expectedTarget && $normalizeTarget($savedTarget) !== $normalizeTarget($expectedTarget))
                || (isset($updates['comment']) && ($saved['comment'] ?? '') !== $updates['comment'])) {
                return [
                    'success' => false,
                    'message' => 'RouterOS did not save the requested queue settings. Expected target ' . ($expectedTarget ?? 'unchanged') . ' and limit ' . ($expectedLimit ?? 'unchanged') . '.',
                ];
            }
            
            Log::info('Queue updated on MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueName,
                'updates' => $updates,
            ]);
            
            return [
                'success' => true,
                'message' => 'Queue updated successfully',
            ];
            
        } catch (Throwable $e) {
            Log::error('Failed to update queue on MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueName,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to update queue: ' . $e->getMessage(),
            ];
        }
    }

    /** Compare RouterOS rate values such as 80M/80M and 80000000/80000000. */
    protected function sameRateLimit(string $expected, string $actual): bool
    {
        $toBits = static function (string $value): ?int {
            $value = strtolower(trim($value));
            if (preg_match('/^(\d+(?:\.\d+)?)([kmg]?)$/', $value, $matches) !== 1) {
                return null;
            }
            $factor = ['' => 1, 'k' => 1_000, 'm' => 1_000_000, 'g' => 1_000_000_000][$matches[2]];
            return (int) round((float) $matches[1] * $factor);
        };
        $expectedParts = explode('/', $expected);
        $actualParts = explode('/', $actual);
        if (count($expectedParts) !== 2 || count($actualParts) !== 2) {
            return false;
        }
        return $toBits($expectedParts[0]) === $toBits($actualParts[0])
            && $toBits($expectedParts[1]) === $toBits($actualParts[1]);
    }

    /**
     * Remove a queue
     * 
     * @param Router $router
     * @param string $queueName
     * @return array{success: bool, message: string}
     */
    public function removeQueue(Router $router, string $queueName): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            // Find the queue by name
            $query = (new Query('/queue/simple/print'))
                ->where('name', $queueName);
            $queues = $client->query($query)->read();
            
            if (empty($queues)) {
                return [
                    'success' => true, // Already removed
                    'message' => 'Queue already removed or not found',
                ];
            }
            
            $queueId = $queues[0]['.id'];
            
            // Remove the queue
            $query = (new Query('/queue/simple/remove'))
                ->equal('.id', $queueId);
            
            $client->query($query)->read();
            
            Log::info('Queue removed from MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueName,
            ]);
            
            return [
                'success' => true,
                'message' => 'Queue removed successfully',
            ];
            
        } catch (Throwable $e) {
            Log::error('Failed to remove queue from MikroTik', [
                'router' => $router->name,
                'queue_name' => $queueName,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => 'Failed to remove queue: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get all queues from router
     * 
     * @param Router $router
     * @return array
     */
    public function getQueues(Router $router): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            $query = new Query('/queue/simple/print');
            $queues = $client->query($query)->read();

            // The dashboard reads this snapshot rather than making its own
            // router connection on every page refresh. A failed VPN/API link
            // therefore never turns the dashboard into a slow or failing page.
            $cacheKey = "router:queues:{$router->id}";
            $previous = Cache::get($cacheKey, []);
            $capturedAt = now()->toIso8601String();
            Cache::put($cacheKey, [
                // Retain exactly one prior sample. Dashboard traffic can then
                // calculate bps from byte counters when RouterOS reports 0/0
                // in its optional instantaneous `rate` property.
                'previous_data' => $previous['data'] ?? [],
                'previous_captured_at' => $previous['captured_at'] ?? null,
                'captured_at' => $capturedAt,
                'data' => $queues,
            ], now()->addMinutes(15));
            
            return [
                'success' => true,
                'data' => $queues,
            ];
            
        } catch (Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ];
        }
    }

    /**
     * Get DHCP leases from router (already implemented above, but ensuring it's here)
     * Returns leases in standardized format
     */
    public function getDhcpLeasesDetailed(Router $router): array
    {
        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)          // TCP connect timeout (s) — dead routers no longer hang requests
                ->set('socket_timeout', 5)   // Read timeout (s)
                ->set('attempts', 1)         // No retry storms
                ->set('delay', 1);

            $client = new Client($config);
            
            $query = new Query('/ip/dhcp-server/lease/print');
            $leases = $client->query($query)->read();
            
            // Parse and format leases
            $formattedLeases = [];
            foreach ($leases as $lease) {
                // MikroTik returns booleans as "true"/"false" strings
                $isDynamic = isset($lease['dynamic'])
                    ? filter_var($lease['dynamic'], FILTER_VALIDATE_BOOLEAN)
                    : true;

                $formattedLeases[] = [
                    'mac_address'   => $lease['mac-address'] ?? $lease['active-mac-address'] ?? null,
                    'ip_address'    => $lease['address'] ?? $lease['active-address'] ?? null,
                    'hostname'      => $lease['host-name'] ?? null,
                    'comment'       => $lease['comment'] ?? null,
                    'rate_limit'    => $lease['rate-limit'] ?? null,
                    'is_dynamic'    => $isDynamic,
                    'status'        => $lease['status'] ?? 'unknown',
                    'server'        => $lease['server'] ?? 'default',
                    'expires_after' => $lease['expires-after'] ?? null,
                    'last_seen'     => $lease['last-seen'] ?? null,
                ];
            }
            
            return [
                'success' => true,
                'data' => $formattedLeases,
                'count' => count($formattedLeases),
            ];
            
        } catch (Throwable $e) {
            Log::error('Failed to fetch DHCP leases', [
                'router' => $router->name,
                'error' => $e->getMessage(),
            ]);
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
                'count' => 0,
            ];
        }
    }


    /**
     * Ensure a DHCP lease is STATIC on MikroTik with the given comment + rate-limit.
     *
     * Business rule (Solarnet): when a client is registered from the Unregistered
     * page, their MikroTik lease must automatically become static, receive their
     * name as the comment, and get their subscription's rate-limit applied — so
     * bandwidth is enforced immediately, no follow-up manual step needed.
     *
     * Behaviour:
     *  - If a lease with the given MAC exists AND is dynamic → make it static, then set fields.
     *  - If it exists and is already static → just set fields.
     *  - If no lease exists (rare — customer added manually with only a MAC) → add a static one.
     *
     * Returns { success: bool, message: string, lease_id?: string } — never throws.
     */
    public function updateOrMakeStaticLease(
        Router $router,
        string $macAddress,
        string $comment,
        ?string $rateLimit = null,
        ?string $ipAddress = null,
        string $server = 'default',
        bool $preserveComment = false
    ): array {
        // Refuse to reach an unreachable router — same guard as QueueService.
        if (in_array($router->connection_status, ['offline', 'unknown', null], true)) {
            return [
                'success' => false,
                'message' => 'Router is not online (connection_status=' . ($router->connection_status ?? 'null') . '). Skipped MikroTik lease sync.',
                'skipped' => true,
            ];
        }

        try {
            $config = (new Config())
                ->set('host', $router->host)
                ->set('user', $router->username)
                ->set('pass', $router->password)
                ->set('port', $router->port)
                ->set('timeout', 3)
                ->set('socket_timeout', 5)
                ->set('attempts', 1)
                ->set('delay', 1);

            $client = new Client($config);
            $macNorm = strtoupper(trim($macAddress));

            // 1) Look up existing lease by MAC
            $find = (new Query('/ip/dhcp-server/lease/print'))
                ->where('mac-address', $macNorm);
            $existing = $client->query($find)->read();
            $lease    = $existing[0] ?? null;

            // Only overwrite the MikroTik comment when we're explicitly allowed to.
            // For static+commented leases the technician's original comment must survive.
            $updates = [];
            if (!$preserveComment) {
                $updates['comment'] = $comment;
            }
            if ($rateLimit) {
                // Force the plan's rate-limit — even if lease already had one.
                $updates['rate-limit'] = $rateLimit;
            }

            if ($lease) {
                $leaseId   = $lease['.id'];
                $isDynamic = isset($lease['dynamic'])
                    ? filter_var($lease['dynamic'], FILTER_VALIDATE_BOOLEAN)
                    : false;

                // Dynamic → convert to static first (MikroTik dedicated command)
                if ($isDynamic) {
                    $mk = (new Query('/ip/dhcp-server/lease/make-static'))
                        ->equal('.id', $leaseId);
                    $client->query($mk)->read();
                    // After make-static, the .id may change — re-lookup by MAC
                    $existing = $client->query($find)->read();
                    $lease    = $existing[0] ?? $lease;
                    $leaseId  = $lease['.id'] ?? $leaseId;
                }

                // 2) Apply updates (comment optionally + rate-limit)
                if (empty($updates)) {
                    return [
                        'success'         => true,
                        'message'         => 'Made static; comment preserved and no rate-limit to apply.',
                        'lease_id'        => $leaseId,
                        'was_dynamic'     => $isDynamic,
                        'comment_kept'    => $preserveComment,
                    ];
                }

                $set = (new Query('/ip/dhcp-server/lease/set'))
                    ->equal('.id', $leaseId);
                foreach ($updates as $k => $v) {
                    $set->equal($k, $v);
                }
                $client->query($set)->read();

                return [
                    'success'      => true,
                    'message'      => $preserveComment
                        ? 'Static lease kept its comment; rate-limit forced to plan.'
                        : 'Lease updated (comment + rate-limit applied).',
                    'lease_id'     => $leaseId,
                    'was_dynamic'  => $isDynamic,
                    'comment_kept' => $preserveComment,
                    'applied'      => array_keys($updates),
                ];
            }

            // 3) No existing lease — add a fresh static one (requires IP).
            //    When there's no lease on MikroTik we always set the comment.
            if (!$ipAddress) {
                return [
                    'success' => false,
                    'message' => 'No existing lease for MAC ' . $macNorm . ' and no IP provided to create a new static lease.',
                ];
            }
            $add = (new Query('/ip/dhcp-server/lease/add'))
                ->equal('mac-address', $macNorm)
                ->equal('address', $ipAddress)
                ->equal('server', $server)
                ->equal('comment', $comment);
            if ($rateLimit) {
                $add->equal('rate-limit', $rateLimit);
            }
            $result = $client->query($add)->read();

            return [
                'success'  => true,
                'message'  => 'Static lease added',
                'lease_id' => $result[0]['ret'] ?? null,
                'created'  => true,
            ];
        } catch (\Throwable $e) {
            Log::warning('updateOrMakeStaticLease failed', [
                'router_id'         => $router->id,
                'mac'               => $macAddress,
                'preserve_comment'  => $preserveComment,
                'rate_limit'        => $rateLimit,
                'error'             => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'message' => 'MikroTik error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Remove one exact inactive static DHCP lease from RouterOS.
     * Customer ownership is validated by the controller before this call;
     * this method independently refuses dynamic or currently bound leases.
     */
    public function removeInactiveStaticLease(Router $router, string $macAddress): array
    {
        if (in_array($router->connection_status, ['offline', 'unknown', null], true)) {
            return ['success' => false, 'message' => 'Router is not online. No lease was removed.'];
        }

        try {
            $config = (new Config())
                ->set('host', $router->host)->set('user', $router->username)
                ->set('pass', $router->password)->set('port', $router->port)
                ->set('timeout', 3)->set('socket_timeout', 5)->set('attempts', 1)->set('delay', 1);
            $client = new Client($config);
            $mac = strtoupper(trim($macAddress));
            $find = (new Query('/ip/dhcp-server/lease/print'))->where('mac-address', $mac);
            $matches = $client->query($find)->read();

            if (count($matches) !== 1) {
                return ['success' => false, 'message' => count($matches) === 0
                    ? 'The exact lease no longer exists on MikroTik.'
                    : 'More than one RouterOS lease uses this MAC. No lease was removed.'];
            }

            $lease = $matches[0];
            $dynamic = filter_var($lease['dynamic'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $status = strtolower((string) ($lease['status'] ?? 'unknown'));
            if ($dynamic || $status === 'bound') {
                return ['success' => false, 'message' => 'Only one inactive static lease can be removed. This RouterOS lease is dynamic or currently bound.'];
            }
            if (empty($lease['.id'])) {
                return ['success' => false, 'message' => 'RouterOS did not return an exact lease identifier. No lease was removed.'];
            }

            $client->query((new Query('/ip/dhcp-server/lease/remove'))->equal('.id', $lease['.id']))->read();
            if ($client->query($find)->read() !== []) {
                return ['success' => false, 'message' => 'RouterOS did not confirm removal of the exact lease.'];
            }

            return ['success' => true, 'message' => "Inactive static lease {$mac} was removed from {$router->name}."];
        } catch (\Throwable $e) {
            Log::warning('Inactive MikroTik DHCP lease removal failed', ['router_id' => $router->id, 'mac' => $macAddress, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'MikroTik lease removal failed: ' . $e->getMessage()];
        }
    }

    /**
     * Add an IP address to a MikroTik firewall address-list.
     */
    public function addAddressList(Router $router, string $listName, string $address, ?string $comment = null): array
    {
        try {
            $client = new Client($this->makeConfig($router));

            $query = (new Query('/ip/firewall/address-list/print'))
                ->where('list', $listName)
                ->where('address', $address);
            $existing = $client->query($query)->read();
            if (!empty($existing)) {
                return [
                    'success' => true,
                    'message' => 'Address already present in list',
                ];
            }

            $add = (new Query('/ip/firewall/address-list/add'))
                ->equal('list', $listName)
                ->equal('address', $address);
            if ($comment !== null && $comment !== '') {
                $add->equal('comment', $comment);
            }
            $client->query($add)->read();

            return [
                'success' => true,
                'message' => 'Address added to address-list',
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to add MikroTik address-list entry', [
                'router_id' => $router->id,
                'list' => $listName,
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to add address-list entry: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Remove an IP address from a MikroTik firewall address-list.
     */
    public function removeAddressList(Router $router, string $listName, string $address): array
    {
        try {
            $client = new Client($this->makeConfig($router));

            $query = (new Query('/ip/firewall/address-list/print'))
                ->where('list', $listName)
                ->where('address', $address);
            $entries = $client->query($query)->read();

            if (empty($entries)) {
                return [
                    'success' => true,
                    'message' => 'Address already absent from list',
                ];
            }

            foreach ($entries as $entry) {
                if (!empty($entry['.id'])) {
                    $remove = (new Query('/ip/firewall/address-list/remove'))
                        ->equal('.id', $entry['.id']);
                    $client->query($remove)->read();
                }
            }

            return [
                'success' => true,
                'message' => 'Address removed from address-list',
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to remove MikroTik address-list entry', [
                'router_id' => $router->id,
                'list' => $listName,
                'address' => $address,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to remove address-list entry: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Install the payment-only firewall policy through the RouterOS API.
     * Only rules whose comments start with our prefix are removed or changed.
     */
    public function installBillingAccessRules(Router $router, string $paymentPortalUrl): array
    {
        $host = parse_url($paymentPortalUrl, PHP_URL_HOST);
        if (!$host) {
            return ['success' => false, 'message' => 'Payment reminder URL must be a valid absolute URL.'];
        }

        $allowedHosts = $this->resolvePaymentHosts(array_merge([$host], self::PAYMENT_CHECKOUT_HOSTS));
        if (!isset($allowedHosts[$host])) {
            return ['success' => false, 'message' => "Could not resolve the payment portal host: {$host}"];
        }
        foreach (self::PAYMENT_CHECKOUT_HOSTS as $checkoutHost) {
            if (!isset($allowedHosts[$checkoutHost])) {
                return ['success' => false, 'message' => "Could not resolve the PayMongo checkout host: {$checkoutHost}. No firewall changes were made."];
            }
        }

        try {
            $client = new Client($this->makeConfig($router));
            if ($this->paymentPortalAddressListHasUnmanagedEntries($client)) {
                return [
                    'success' => false,
                    'message' => 'The solarnet_payment_portal address list contains entries not created by SolarNet. No firewall changes were made.',
                ];
            }
            $this->ensurePaymentPortalAddressList($client, $allowedHosts);
            $this->removeBillingFilterRules($client);

            // RouterOS versions differ in how they interpret numeric
            // `place-before` values over the API. Add the managed rules, then
            // explicitly move them into their required order below.
            $rules = [
                ['protocol' => null,  'dst_port' => null,     'dst_address' => null,       'src_address_list' => self::PAYMENT_SESSION_ADDRESS_LIST, 'action' => 'accept', 'comment' => self::BILLING_RULE_PREFIX . ' allow temporary payment checkout'],
                ['protocol' => null,  'dst_port' => null,     'dst_address' => null,       'action' => 'drop',   'comment' => self::BILLING_RULE_PREFIX . ' block internet'],
                ['protocol' => 'tcp', 'dst_port' => '53',     'dst_address' => null,       'action' => 'accept', 'comment' => self::BILLING_RULE_PREFIX . ' allow DNS TCP'],
                ['protocol' => 'udp', 'dst_port' => '53',     'dst_address' => null,       'action' => 'accept', 'comment' => self::BILLING_RULE_PREFIX . ' allow DNS UDP'],
                ['protocol' => 'tcp', 'dst_port' => '80,443', 'dst_address_list' => self::PAYMENT_PORTAL_ADDRESS_LIST, 'action' => 'accept', 'comment' => self::BILLING_RULE_PREFIX . ' allow payment portal'],
            ];

            foreach ($rules as $rule) {
                $query = (new Query('/ip/firewall/filter/add'))
                    ->equal('chain', 'forward')
                    ->equal('src-address-list', $rule['src_address_list'] ?? self::SUSPENDED_ADDRESS_LIST)
                    ->equal('action', $rule['action'])
                    ->equal('comment', $rule['comment']);
                if ($rule['protocol']) $query->equal('protocol', $rule['protocol']);
                if ($rule['dst_port']) $query->equal('dst-port', $rule['dst_port']);
                if (!empty($rule['dst_address_list'])) $query->equal('dst-address-list', $rule['dst_address_list']);
                $client->query($query)->read();
            }

            $this->orderBillingFilterRules($client);

            $this->ensureSuspendedAddressList($client);

            return [
                'success' => true,
                'message' => 'Installed payment-only access rules for the customer portal and PayMongo GCash checkout.',
                'payment_portal_host' => $host,
                'payment_portal_ip' => $allowedHosts[$host][0],
                'allowed_payment_hosts' => array_keys($allowedHosts),
                'rules_installed' => 5,
            ];
        } catch (Throwable $e) {
            Log::warning('Failed to install billing firewall rules', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to install billing firewall rules: ' . $e->getMessage()];
        }
    }

    public function billingAccessRulesStatus(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $rules = $this->billingFilterRules($client);
            $paymentPortalEntries = $this->paymentPortalAddressListEntries($client);
            $audit = $this->billingNetworkAudit($client);
            return [
                'success' => true,
                'installed' => count($rules) === 5 && count($paymentPortalEntries) >= 1 + count(self::PAYMENT_CHECKOUT_HOSTS),
                'rule_count' => count($rules),
                'payment_portal_entries' => array_map(fn (array $entry) => [
                    'address' => $entry['address'] ?? null,
                    'comment' => $entry['comment'] ?? '',
                    'disabled' => ($entry['disabled'] ?? 'false') === 'true',
                ], $paymentPortalEntries),
                'audit' => $audit,
                'rules' => array_map(fn (array $rule) => [
                    'id' => $rule['.id'] ?? null,
                    'action' => $rule['action'] ?? null,
                    'protocol' => $rule['protocol'] ?? 'any',
                    'dst_address' => $rule['dst-address'] ?? 'any',
                    'dst_port' => $rule['dst-port'] ?? 'any',
                    'disabled' => ($rule['disabled'] ?? 'false') === 'true',
                    'comment' => $rule['comment'] ?? '',
                ], $rules),
            ];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Failed to verify billing firewall rules: ' . $e->getMessage()];
        }
    }

    /**
     * Read-only safety inspection. The billing policy is address-list based,
     * so it protects all detected customer DHCP VLANs without enabling a
     * RouterOS Hotspot on an interface.
     */
    public function billingAccessAudit(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            return ['success' => true, 'audit' => $this->billingNetworkAudit($client)];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Failed to read router network configuration: ' . $e->getMessage()];
        }
    }

    public function removeBillingAccessRules(Router $router): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $removed = $this->removeBillingFilterRules($client);
            return ['success' => true, 'message' => "Removed {$removed} Solarnet billing firewall rule(s).", 'removed' => $removed];
        } catch (Throwable $e) {
            Log::warning('Failed to remove billing firewall rules', ['router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to remove billing firewall rules: ' . $e->getMessage()];
        }
    }

    private function billingFilterRules(Client $client): array
    {
        $rules = $client->query(new Query('/ip/firewall/filter/print'))->read();
        return array_values(array_filter($rules, fn (array $rule) => str_starts_with((string) ($rule['comment'] ?? ''), self::BILLING_RULE_PREFIX)));
    }

    private function removeBillingFilterRules(Client $client): int
    {
        $rules = $this->billingFilterRules($client);
        foreach ($rules as $rule) {
            if (!empty($rule['.id'])) {
                $client->query((new Query('/ip/firewall/filter/remove'))->equal('.id', $rule['.id']))->read();
            }
        }
        return count($rules);
    }

    /** Ensure allow rules always precede the suspended-client drop rule. */
    private function orderBillingFilterRules(Client $client): void
    {
        $order = [
            self::BILLING_RULE_PREFIX . ' allow temporary payment checkout',
            self::BILLING_RULE_PREFIX . ' allow payment portal',
            self::BILLING_RULE_PREFIX . ' allow DNS UDP',
            self::BILLING_RULE_PREFIX . ' allow DNS TCP',
            self::BILLING_RULE_PREFIX . ' block internet',
        ];

        foreach ($order as $position => $comment) {
            $rules = $client->query((new Query('/ip/firewall/filter/print'))->where('comment', $comment))->read();
            $ruleId = $rules[0]['.id'] ?? null;
            if (!$ruleId) {
                throw new \RuntimeException("Billing firewall rule is missing: {$comment}");
            }

            $client->query(
                (new Query('/ip/firewall/filter/move'))
                    ->equal('numbers', $ruleId)
                    ->equal('destination', (string) $position)
            )->read();
        }
    }

    private function ensureSuspendedAddressList(Client $client): void
    {
        $entries = $client->query((new Query('/ip/firewall/address-list/print'))->where('list', self::SUSPENDED_ADDRESS_LIST))->read();
        if (empty($entries)) {
            $client->query(
                (new Query('/ip/firewall/address-list/add'))
                    ->equal('list', self::SUSPENDED_ADDRESS_LIST)
                    ->equal('address', '0.0.0.0')
                    ->equal('disabled', 'true')
                    ->equal('comment', 'Solarnet Billing placeholder - do not enable')
            )->read();
        }
    }

    /** Grant time-limited full access only after a suspended client starts GCash checkout. */
    public function grantTemporaryPaymentCheckoutAccess(Customer $customer, int $minutes = 1440): array
    {
        if (!in_array($customer->status, ['suspended', 'expired'], true)) {
            return ['success' => true, 'granted' => false, 'message' => 'Temporary payment access is not needed for this customer status.'];
        }

        $router = $customer->router;
        $ipAddress = $customer->ip_address;
        if (!$router || !filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['success' => false, 'granted' => false, 'message' => 'The customer does not have a valid router and IPv4 address for payment access.'];
        }

        // A full day is convenient for payment retries but still expires on
        // RouterOS automatically; never create a permanent bypass.
        $minutes = max(1, min($minutes, 1440));
        $comment = 'Solarnet Billing temporary payment checkout ' . $customer->id;
        try {
            $client = new Client($this->makeConfig($router));
            $entries = $client->query((new Query('/ip/firewall/address-list/print'))->where('list', self::PAYMENT_SESSION_ADDRESS_LIST))->read();
            foreach ($entries as $entry) {
                if (($entry['comment'] ?? '') === $comment && !empty($entry['.id'])) {
                    $client->query((new Query('/ip/firewall/address-list/remove'))->equal('.id', $entry['.id']))->read();
                }
            }
            $client->query(
                (new Query('/ip/firewall/address-list/add'))
                    ->equal('list', self::PAYMENT_SESSION_ADDRESS_LIST)
                    ->equal('address', $ipAddress)
                    ->equal('timeout', $minutes . 'm')
                    ->equal('comment', $comment)
            )->read();

            return ['success' => true, 'granted' => true, 'message' => "Temporary payment access was granted for {$minutes} minutes."];
        } catch (Throwable $e) {
            Log::warning('Failed to grant temporary PayMongo checkout access', ['customer_id' => $customer->id, 'router_id' => $router->id, 'error' => $e->getMessage()]);
            return ['success' => false, 'granted' => false, 'message' => 'Could not grant temporary payment access.'];
        }
    }

    /**
     * Refresh only the address-list entries owned by the billing application.
     * The firewall rule points at this list, so a later refresh never needs to
     * touch customer entries or unrelated firewall rules.
     */
    private function ensurePaymentPortalAddressList(Client $client, array $allowedHosts): void
    {
        foreach ($this->paymentPortalAddressListEntries($client) as $entry) {
            if (!empty($entry['.id'])) {
                $client->query((new Query('/ip/firewall/address-list/remove'))->equal('.id', $entry['.id']))->read();
            }
        }

        foreach ($allowedHosts as $host => $ips) {
            foreach ($ips as $ip) {
                $client->query(
                    (new Query('/ip/firewall/address-list/add'))
                        ->equal('list', self::PAYMENT_PORTAL_ADDRESS_LIST)
                        ->equal('address', $ip)
                        ->equal('comment', self::PAYMENT_PORTAL_COMMENT_PREFIX . ' ' . $host)
                )->read();
            }
        }
    }

    /** @return array<string, list<string>> Hostname => all resolved IPv4 addresses. */
    private function resolvePaymentHosts(array $hosts): array
    {
        $resolved = [];
        foreach (array_unique($hosts) as $host) {
            $ips = gethostbynamel($host) ?: [gethostbyname($host)];
            $ips = array_values(array_unique(array_filter($ips, fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4))));
            if ($ips !== []) {
                $resolved[$host] = $ips;
            }
        }

        return $resolved;
    }

    private function paymentPortalAddressListEntries(Client $client): array
    {
        $entries = $client->query((new Query('/ip/firewall/address-list/print'))->where('list', self::PAYMENT_PORTAL_ADDRESS_LIST))->read();

        return array_values(array_filter($entries, fn (array $entry) => str_starts_with(
            (string) ($entry['comment'] ?? ''),
            self::PAYMENT_PORTAL_COMMENT_PREFIX,
        )));
    }

    private function paymentPortalAddressListHasUnmanagedEntries(Client $client): bool
    {
        $entries = $client->query((new Query('/ip/firewall/address-list/print'))->where('list', self::PAYMENT_PORTAL_ADDRESS_LIST))->read();

        return collect($entries)->contains(fn (array $entry) => !str_starts_with(
            (string) ($entry['comment'] ?? ''),
            self::PAYMENT_PORTAL_COMMENT_PREFIX,
        ));
    }

    private function billingNetworkAudit(Client $client): array
    {
        $dhcpServers = $client->query(new Query('/ip/dhcp-server/print'))->read();
        $addresses = $client->query(new Query('/ip/address/print'))->read();
        $hotspots = $client->query(new Query('/ip/hotspot/print'))->read();
        $dhcpInterfaces = array_values(array_unique(array_filter(array_map(
            fn (array $server) => $server['interface'] ?? null,
            $dhcpServers,
        ))));
        $addressByInterface = [];
        foreach ($addresses as $address) {
            $interface = $address['interface'] ?? null;
            if ($interface && in_array($interface, $dhcpInterfaces, true)) {
                $addressByInterface[$interface] = $address['address'] ?? null;
            }
        }

        return [
            'dhcp_server_count' => count($dhcpServers),
            'customer_interfaces' => array_map(fn (string $interface) => [
                'interface' => $interface,
                'gateway' => $addressByInterface[$interface] ?? null,
            ], $dhcpInterfaces),
            'hotspot_count' => count($hotspots),
            'hotspot_interfaces' => array_values(array_filter(array_map(fn (array $hotspot) => $hotspot['interface'] ?? null, $hotspots))),
            'recommended_mode' => 'address-list firewall policy',
            'hotspot_change_required' => false,
            'safety_note' => count($hotspots) > 0
                ? 'Existing Hotspot configuration was detected and will not be changed.'
                : 'No Hotspot configuration will be created. The policy is limited to suspended IP addresses only.',
        ];
    }

    /** Run a one-time RouterOS script and delete the temporary script afterward. */
    public function runOneTimeScript(Router $router, string $source, ?string $executedBy = null): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $name = 'solarnet-once-' . substr(str_replace('-', '', (string) \Illuminate\Support\Str::uuid()), 0, 12);

            $client->query(
                (new Query('/system/script/add'))
                    ->equal('name', $name)
                    ->equal('source', $source)
                    ->equal('policy', 'read,write,policy,test')
                    ->equal('comment', 'Solarnet one-time console command')
            )->read();

            try {
                $scripts = $client->query((new Query('/system/script/print'))->where('name', $name))->read();
                $scriptId = $scripts[0]['.id'] ?? null;
                if (!$scriptId) {
                    throw new \RuntimeException('RouterOS did not return the temporary script.');
                }
                $result = $client->query((new Query('/system/script/run'))->equal('.id', $scriptId))->read();
            } finally {
                // Always remove the temporary script, including when RouterOS
                // reports a script error. The submitted source is never saved.
                $scripts = $client->query((new Query('/system/script/print'))->where('name', $name))->read();
                foreach ($scripts as $script) {
                    if (!empty($script['.id'])) {
                        $client->query((new Query('/system/script/remove'))->equal('.id', $script['.id']))->read();
                    }
                }
            }

            Log::info('One-time MikroTik console script executed', [
                'router_id' => $router->id,
                'executed_by' => $executedBy,
                'source_length' => strlen($source),
            ]);

            return [
                'success' => true,
                'message' => 'Script executed. The temporary RouterOS script was removed.',
                'result' => $result,
            ];
        } catch (Throwable $e) {
            Log::warning('MikroTik console script failed', [
                'router_id' => $router->id,
                'executed_by' => $executedBy,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'message' => 'Script failed: ' . $e->getMessage()];
        }
    }

    /** Run RouterOS /ping through the API and return its response rows. */
    public function ping(Router $router, string $address, int $count = 4): array
    {
        try {
            $client = new Client($this->makeConfig($router));
            $rows = $client->query(
                (new Query('/ping'))
                    ->equal('address', $address)
                    ->equal('count', (string) max(1, min($count, 10)))
            )->read();
            return ['success' => true, 'message' => "Ping completed for {$address}.", 'rows' => $rows];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Ping failed: ' . $e->getMessage()];
        }
    }

    /** Read WireGuard peer runtime counters from RouterOS without changing it. */
    public function wireguardPeerStatus(Router $router, string $interfaceName, string $serverPublicKey): array
    {
        try {
            $client = new Client($this->makeConfig($router, 3, 8));
            $interfaces = $client->query('/interface/wireguard/print')->read();
            $interface = collect($interfaces)->first(fn (array $row) => ($row['name'] ?? '') === $interfaceName);
            if (! $interface) {
                return ['success' => false, 'code' => 'INTERFACE_NOT_FOUND', 'message' => "WireGuard interface {$interfaceName} was not found on RouterOS."];
            }

            $peers = $client->query('/interface/wireguard/peers/print')->read();
            $peer = collect($peers)->first(fn (array $row) => hash_equals((string) ($row['public-key'] ?? ''), $serverPublicKey));
            if (! $peer) {
                return ['success' => false, 'code' => 'PEER_NOT_FOUND', 'message' => 'The saved VPS public key is not present in the selected RouterOS WireGuard peer list.'];
            }

            return ['success' => true, 'data' => [
                'interface' => $interfaceName,
                'running' => ($interface['running'] ?? 'false') === 'true' && ($interface['disabled'] ?? 'false') !== 'true',
                'latest_handshake' => $peer['last-handshake'] ?? null,
                'rx_bytes' => (int) ($peer['rx'] ?? 0),
                'tx_bytes' => (int) ($peer['tx'] ?? 0),
                'current_endpoint' => $peer['current-endpoint-address'] ?? null,
                'current_endpoint_port' => $peer['current-endpoint-port'] ?? null,
                'disabled' => ($peer['disabled'] ?? 'false') === 'true',
            ]];
        } catch (Throwable $e) {
            return ['success' => false, 'code' => 'ROUTER_UNREACHABLE', 'message' => 'Could not read WireGuard status from RouterOS: '.$e->getMessage()];
        }
    }

    /**
     * Read one SNMPv2c OID from a device reachable by the router itself.
     *
     * This is intentionally a narrow relay primitive, not a RouterOS console
     * or generic command executor. Callers provide only a single validated
     * OID and receive a value; it never sends SNMP SET/WALK traffic and never
     * persists the community on RouterOS.
     */
    public function relaySnmpV2cGet(Router $router, string $address, int $port, string $community, string $oid): array
    {
        if (!filter_var($address, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535 || !preg_match('/^\.?(?:\d+\.)*\d+$/', $oid)) {
            return ['success' => false, 'code' => 'RELAY_INPUT_INVALID', 'message' => 'The OLT SNMP relay request contains an invalid address, port, or OID.'];
        }

        try {
            $client = new Client($this->makeConfig($router, 3, 5));
            $rows = $client->query(
                (new Query('/tool/snmp-get'))
                    ->equal('address', $address)
                    ->equal('port', (string) $port)
                    ->equal('version', '2c')
                    ->equal('community', $community)
                    ->equal('oid', ltrim($oid, '.'))
                    ->equal('tries', '1')
                    ->equal('try-timeout', '2s')
            )->read();

            $value = $this->routerOsSnmpValue($rows);
            if ($value === null) {
                return [
                    'success' => false,
                    'code' => 'RELAY_NO_SNMP_RESPONSE',
                    'message' => 'RouterOS did not receive an SNMP value from the OLT.',
                ];
            }

            return ['success' => true, 'value' => $value];
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $normalized = strtolower($message);
            $code = str_contains($normalized, 'not enough permissions')
                || str_contains($normalized, 'permission denied')
                || str_contains($normalized, 'not permitted')
                ? 'RELAY_ROUTER_PERMISSION_MISSING'
                : 'RELAY_ROUTER_ERROR';

            Log::warning('Read-only OLT SNMP relay failed', [
                'router_id' => $router->id,
                'olt_host' => $address,
                'olt_port' => $port,
                'oid' => $oid,
                'code' => $code,
                'error' => $message,
            ]);

            return [
                'success' => false,
                'code' => $code,
                'message' => $code === 'RELAY_ROUTER_PERMISSION_MISSING'
                    ? 'The router API account cannot run the read-only RouterOS SNMP tool.'
                    : 'RouterOS could not complete the read-only SNMP relay request.',
            ];
        }
    }

    /**
     * Read a fixed, allowlisted SNMP table column through the router itself.
     *
     * This is deliberately a narrow read-only primitive for OLT monitoring.
     * It does not accept browser input, does not run scripts, and never sends
     * SNMP SET traffic. The OLT service supplies only standard IF-MIB columns
     * and caps the returned rows before they reach the application.
     *
     * @return array{success: bool, rows?: array<int, array{index: int, value: string}>, truncated?: bool, code?: string, message?: string}
     */
    public function relaySnmpV2cWalk(Router $router, string $address, int $port, string $community, string $oid, int $maxRows = 512): array
    {
        if (!filter_var($address, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535 || !preg_match('/^\.?(?:\d+\.)*\d+$/', $oid)) {
            return ['success' => false, 'code' => 'RELAY_INPUT_INVALID', 'message' => 'The OLT SNMP relay request contains an invalid address, port, or OID.'];
        }

        $maxRows = max(1, min($maxRows, 512));

        try {
            $client = new Client($this->makeConfig($router, 3, 8));
            $rows = $client->query(
                (new Query('/tool/snmp-walk'))
                    ->equal('address', $address)
                    ->equal('port', (string) $port)
                    ->equal('version', '2c')
                    ->equal('community', $community)
                    ->equal('oid', ltrim($oid, '.'))
                    ->equal('tries', '1')
                    ->equal('try-timeout', '2s')
            )->read();

            $walk = $this->routerOsSnmpWalkRows($rows, $oid, $maxRows);
            if ($walk['rows'] === []) {
                return [
                    'success' => false,
                    'code' => 'RELAY_NO_SNMP_RESPONSE',
                    'message' => 'RouterOS did not receive usable SNMP table values from the OLT.',
                ];
            }

            return ['success' => true, 'rows' => $walk['rows'], 'truncated' => $walk['truncated']];
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $normalized = strtolower($message);
            $code = str_contains($normalized, 'not enough permissions')
                || str_contains($normalized, 'permission denied')
                || str_contains($normalized, 'not permitted')
                ? 'RELAY_ROUTER_PERMISSION_MISSING'
                : 'RELAY_ROUTER_ERROR';

            Log::warning('Read-only OLT SNMP table relay failed', [
                'router_id' => $router->id,
                'olt_host' => $address,
                'olt_port' => $port,
                'oid' => $oid,
                'code' => $code,
                'error' => $message,
            ]);

            return [
                'success' => false,
                'code' => $code,
                'message' => $code === 'RELAY_ROUTER_PERMISSION_MISSING'
                    ? 'The router API account cannot run the read-only RouterOS SNMP tool.'
                    : 'RouterOS could not complete the read-only SNMP table relay.',
            ];
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function routerOsSnmpValue(array $rows): ?string
    {
        foreach ($rows as $row) {
            foreach (['value', 'ret', 'result'] as $key) {
                $value = $row[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') return trim((string) $value);
            }
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{rows: array<int, array{index: int, value: string}>, truncated: bool}
     */
    private function routerOsSnmpWalkRows(array $rows, string $baseOid, int $maxRows): array
    {
        $baseOid = rtrim(ltrim($baseOid, '.'), '.') . '.';
        $entries = [];
        $truncated = false;

        foreach ($rows as $row) {
            $oid = $row['oid'] ?? $row['oid-name'] ?? $row['name'] ?? null;
            $value = $row['value'] ?? $row['ret'] ?? $row['result'] ?? null;
            if (!is_scalar($oid) || !is_scalar($value)) continue;

            $oid = ltrim(trim((string) $oid), '.');
            if (!str_starts_with($oid, $baseOid)) continue;

            $index = substr($oid, strlen($baseOid));
            if (!preg_match('/^\d+$/', $index)) continue;

            if (count($entries) >= $maxRows) {
                $truncated = true;
                break;
            }

            $entries[(int) $index] = ['index' => (int) $index, 'value' => trim((string) $value)];
        }

        ksort($entries, SORT_NUMERIC);

        return ['rows' => array_values($entries), 'truncated' => $truncated];
    }
}
