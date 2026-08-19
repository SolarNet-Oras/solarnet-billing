<?php

namespace App\Services;

/**
 * Interprets a deliberately small, read-only RouterOS security inventory.
 *
 * This class never connects to a router and does not attempt to build a
 * firewall configuration. Its output is evidence and recommendations only;
 * a missing rule is not silently "fixed" by the application.
 */
class RouterSecurityBaselineAnalyzer
{
    /**
     * @param array<string, array<int, array<string, mixed>>> $inventory
     * @return array<string, mixed>
     */
    public function analyze(array $inventory): array
    {
        $filters = $inventory['filters'] ?? [];
        $nat = $inventory['nat'] ?? [];
        $services = $inventory['services'] ?? [];
        $interfaceLists = $inventory['interface_lists'] ?? [];
        $listMembers = $inventory['interface_list_members'] ?? [];
        $wireguard = $inventory['wireguard'] ?? [];
        $ipv6Filters = $inventory['ipv6_filters'] ?? [];
        $ipv6Addresses = $inventory['ipv6_addresses'] ?? [];
        $addressLists = $inventory['address_lists'] ?? [];

        $enabledFilters = $this->enabled($filters);
        $enabledIpv6Filters = $this->enabled($ipv6Filters);
        $enabledServices = $this->enabled($services);
        $enabledWireguard = $this->enabled($wireguard);
        $checks = [];

        $checks[] = $this->check(
            'input_invalid_drop',
            'Invalid input traffic is dropped',
            $this->hasStateAction($enabledFilters, 'input', 'invalid', 'drop') ? 'pass' : 'attention',
            $this->hasStateAction($enabledFilters, 'input', 'invalid', 'drop')
                ? 'An enabled input-chain drop rule matches invalid connection state.'
                : 'No enabled input-chain invalid-state drop rule was found.',
            'Review the existing input chain and add an invalid-state drop only during a maintenance window after confirming rule order and management access.'
        );

        $checks[] = $this->check(
            'forward_invalid_drop',
            'Invalid forwarded traffic is dropped',
            $this->hasStateAction($enabledFilters, 'forward', 'invalid', 'drop') ? 'pass' : 'attention',
            $this->hasStateAction($enabledFilters, 'forward', 'invalid', 'drop')
                ? 'An enabled forward-chain drop rule matches invalid connection state.'
                : 'No enabled forward-chain invalid-state drop rule was found.',
            'Review the existing forward chain before adding any rule. Customer billing, queue, VLAN, and payment rules must remain in their current order.'
        );

        $checks[] = $this->check(
            'input_established_related',
            'Established and related management replies are allowed',
            $this->hasEstablishedRelatedAccept($enabledFilters, 'input') ? 'pass' : 'attention',
            $this->hasEstablishedRelatedAccept($enabledFilters, 'input')
                ? 'An enabled input-chain accept rule includes established and related traffic.'
                : 'No enabled input-chain accept rule was found that includes both established and related traffic.',
            'Confirm the input-chain order. Keep return traffic allowed before any restrictive drop rule so remote management is not interrupted.'
        );

        $inputDropCount = $this->countAction($enabledFilters, 'input', 'drop');
        $checks[] = $this->check(
            'input_drop_control',
            'Router management has an input-chain drop control',
            $inputDropCount > 0 ? 'pass' : 'high',
            $inputDropCount > 0
                ? "{$inputDropCount} enabled input-chain drop rule(s) were found. This check does not prove their order or WAN scope."
                : 'No enabled input-chain drop rule was found in the returned firewall inventory.',
            'Treat this as urgent review before exposing any router service. Confirm an explicit management allow-list and a final WAN-facing input drop using Safe Mode or an out-of-band session.'
        );

        $management = $this->managementServiceChecks($enabledServices);
        foreach ($management['checks'] as $check) $checks[] = $check;

        $wanLists = $this->wanLists($interfaceLists, $listMembers);
        $checks[] = $this->check(
            'wan_interface_lists',
            'WAN interface-list evidence',
            $wanLists === [] ? 'review' : 'pass',
            $wanLists === []
                ? 'No interface list named WAN or INTERNET with an enabled member was found.'
                : 'Detected interface-list membership: ' . implode(', ', $wanLists) . '.',
            'Do not assume an interface name. Review the actual uplink interfaces before using any WAN-scoped firewall recommendation.'
        );

        $checks[] = $this->check(
            'wireguard_presence',
            'WireGuard management path',
            $enabledWireguard === [] ? 'review' : 'pass',
            $enabledWireguard === []
                ? 'No enabled RouterOS WireGuard interface was returned.'
                : 'Enabled WireGuard interface(s): ' . $this->names($enabledWireguard) . '. This confirms configuration presence, not tunnel health.',
            'If remote administration is required, prefer a restricted VPN management path. Verify peers and firewall policy separately; this inspection does not expose keys or peer details.'
        );

        $configuredIpv6 = $this->configuredIpv6Addresses($ipv6Addresses);
        if ($configuredIpv6 === []) {
            $checks[] = $this->check(
                'ipv6_firewall',
                'IPv6 firewall coverage',
                'not_applicable',
                'No non-link-local IPv6 address was returned, so this baseline does not evaluate IPv6 exposure.',
                'If IPv6 is enabled later, inspect its input and forward chains independently. IPv4 firewall rules do not protect IPv6 traffic.'
            );
        } else {
            $ipv6InputDrop = $this->countAction($enabledIpv6Filters, 'input', 'drop');
            $ipv6ForwardDrop = $this->countAction($enabledIpv6Filters, 'forward', 'drop');
            $checks[] = $this->check(
                'ipv6_firewall',
                'IPv6 firewall coverage',
                $ipv6InputDrop > 0 && $ipv6ForwardDrop > 0 ? 'pass' : 'high',
                "{$ipv6InputDrop} enabled IPv6 input drop rule(s) and {$ipv6ForwardDrop} enabled IPv6 forward drop rule(s) were returned for {$configuredIpv6[0]}.",
                'Review IPv6 firewall policy before relying on it. Confirm both input and forward controls are scoped correctly and do not break required IPv6 service.'
            );
        }

        $threatEntries = array_values(array_filter($addressLists, fn (array $row) => strtolower((string) ($row['list'] ?? '')) === 'solarnet_threat_feed'));
        $threatRules = array_values(array_filter($enabledFilters, fn (array $row) => str_contains(strtolower((string) ($row['comment'] ?? '')), 'solarnet threat feed')));
        $checks[] = $this->check(
            'solarnet_threat_controls',
            'SolarNet reviewed threat controls',
            $threatRules !== [] ? 'pass' : 'review',
            $threatRules !== []
                ? count($threatRules) . ' SolarNet-owned reviewed threat rule(s) and ' . count($threatEntries) . ' current list entr' . (count($threatEntries) === 1 ? 'y' : 'ies') . ' were found.'
                : 'No SolarNet-owned reviewed threat-feed firewall rule was found. This is normal until an administrator approves a candidate.',
            'Use the manual feed review workflow for a bounded connection sample. Approve a candidate only after validating it; blocks are temporary by default.'
        );

        $masquerade = count(array_filter($this->enabled($nat), fn (array $row) => strtolower((string) ($row['action'] ?? '')) === 'masquerade'));
        $statuses = array_count_values(array_map(fn (array $check) => $check['status'], $checks));

        return [
            'summary' => [
                'total_checks' => count($checks),
                'passing_checks' => $statuses['pass'] ?? 0,
                'attention_checks' => ($statuses['attention'] ?? 0) + ($statuses['review'] ?? 0),
                'high_risk_checks' => $statuses['high'] ?? 0,
                'status' => ($statuses['high'] ?? 0) > 0 ? 'needs_review' : ((($statuses['attention'] ?? 0) + ($statuses['review'] ?? 0)) > 0 ? 'review' : 'ready'),
            ],
            'checks' => $checks,
            'inventory' => [
                'firewall_filter_rules' => count($filters),
                'enabled_input_rules' => count(array_filter($enabledFilters, fn (array $row) => strtolower((string) ($row['chain'] ?? '')) === 'input')),
                'enabled_forward_rules' => count(array_filter($enabledFilters, fn (array $row) => strtolower((string) ($row['chain'] ?? '')) === 'forward')),
                'firewall_nat_rules' => count($nat),
                'masquerade_rules' => $masquerade,
                'enabled_management_services' => $management['services'],
                'wireguard_interfaces' => array_values(array_map(fn (array $row) => ['name' => (string) ($row['name'] ?? 'unnamed'), 'listen_port' => $row['listen-port'] ?? null], $enabledWireguard)),
                'wan_interface_lists' => $wanLists,
                'ipv6_configured' => $configuredIpv6 !== [],
                'ipv6_filter_rules' => count($ipv6Filters),
                'solarnet_threat_list_entries' => count($threatEntries),
            ],
            'safety' => 'No RouterOS configuration was changed. This inspection used RouterOS read-only print commands only and did not alter firewall, NAT, DHCP, queues, VLANs, VPN, DNS, or billing controls.',
        ];
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, array<string, mixed>> */
    private function enabled(array $rows): array
    {
        return array_values(array_filter($rows, fn (array $row) => !in_array(strtolower((string) ($row['disabled'] ?? 'false')), ['true', 'yes', '1'], true)));
    }

    /** @param array<int, array<string, mixed>> $rules */
    private function hasStateAction(array $rules, string $chain, string $state, string $action): bool
    {
        return (bool) collect($rules)->first(fn (array $rule) => strtolower((string) ($rule['chain'] ?? '')) === $chain
            && strtolower((string) ($rule['action'] ?? '')) === $action
            && in_array($state, $this->states($rule), true));
    }

    /** @param array<int, array<string, mixed>> $rules */
    private function hasEstablishedRelatedAccept(array $rules, string $chain): bool
    {
        return (bool) collect($rules)->first(function (array $rule) use ($chain) {
            $states = $this->states($rule);
            return strtolower((string) ($rule['chain'] ?? '')) === $chain
                && strtolower((string) ($rule['action'] ?? '')) === 'accept'
                && in_array('established', $states, true)
                && in_array('related', $states, true);
        });
    }

    /** @param array<int, array<string, mixed>> $rules */
    private function countAction(array $rules, string $chain, string $action): int
    {
        return count(array_filter($rules, fn (array $rule) => strtolower((string) ($rule['chain'] ?? '')) === $chain && strtolower((string) ($rule['action'] ?? '')) === $action));
    }

    /** @param array<string, mixed> $rule @return array<int, string> */
    private function states(array $rule): array
    {
        return array_values(array_filter(array_map('trim', explode(',', strtolower((string) ($rule['connection-state'] ?? ''))))));
    }

    /** @param array<int, array<string, mixed>> $services @return array{checks: array<int, array<string, mixed>>, services: array<int, array<string, mixed>>} */
    private function managementServiceChecks(array $services): array
    {
        $sensitive = ['api', 'api-ssl', 'ssh', 'winbox', 'www', 'www-ssl', 'telnet', 'ftp'];
        $checks = [];
        $exposed = [];
        foreach ($services as $service) {
            $name = strtolower((string) ($service['name'] ?? ''));
            if (!in_array($name, $sensitive, true)) continue;
            $address = trim((string) ($service['address'] ?? ''));
            $restricted = $address !== '' && $address !== '0.0.0.0/0';
            $legacy = in_array($name, ['telnet', 'ftp'], true);
            $status = $legacy ? 'high' : ($restricted ? 'pass' : 'attention');
            $port = (string) ($service['port'] ?? 'default');
            $checks[] = $this->check(
                'management_service_' . str_replace('-', '_', $name),
                strtoupper($name) . ' management service scope',
                $status,
                $restricted
                    ? "{$name} is enabled on port {$port} and restricted by its RouterOS service address setting ({$address})."
                    : "{$name} is enabled on port {$port} without a RouterOS service address restriction. Input firewall policy may still restrict it.",
                $legacy
                    ? 'Disable Telnet/FTP unless there is a documented, isolated legacy need. Prefer SSH, API-SSL, or a restricted VPN management path.'
                    : 'Confirm the input firewall and management allow-list. Bind the service to approved management sources where operationally safe; do not remove access from the active management path.'
            );
            $exposed[] = ['name' => $name, 'port' => $service['port'] ?? null, 'address' => $address === '' ? null : $address, 'restricted_at_service_layer' => $restricted];
        }

        if ($checks === []) {
            $checks[] = $this->check('management_services', 'RouterOS management services', 'review', 'No enabled sensitive RouterOS management service was returned.', 'Confirm required management access directly in RouterOS before changing a service.');
        }

        return ['checks' => $checks, 'services' => $exposed];
    }

    /** @param array<int, array<string, mixed>> $lists @param array<int, array<string, mixed>> $members @return array<int, string> */
    private function wanLists(array $lists, array $members): array
    {
        $wanIds = array_flip(array_map(fn (array $list) => (string) ($list['name'] ?? ''), array_filter($lists, fn (array $list) => preg_match('/(^|[-_ ])(wan|internet)([-_ ]|$)/i', (string) ($list['name'] ?? '')) === 1)));
        if ($wanIds === []) return [];
        $result = [];
        foreach ($members as $member) {
            $list = (string) ($member['list'] ?? '');
            if (!isset($wanIds[$list]) || in_array(strtolower((string) ($member['disabled'] ?? 'false')), ['true', 'yes', '1'], true)) continue;
            $interface = (string) ($member['interface'] ?? '');
            if ($interface !== '') $result[] = "{$list}: {$interface}";
        }
        return array_values(array_unique($result));
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function names(array $rows): string
    {
        return implode(', ', array_filter(array_map(fn (array $row) => (string) ($row['name'] ?? ''), $rows)));
    }

    /** @param array<int, array<string, mixed>> $addresses @return array<int, string> */
    private function configuredIpv6Addresses(array $addresses): array
    {
        return array_values(array_filter(array_map(fn (array $row) => (string) ($row['address'] ?? ''), $this->enabled($addresses)), fn (string $address) => $address !== '' && !str_starts_with(strtolower($address), 'fe80:')));
    }

    /** @return array<string, mixed> */
    private function check(string $id, string $title, string $status, string $evidence, string $recommendation): array
    {
        return compact('id', 'title', 'status', 'evidence', 'recommendation');
    }
}
