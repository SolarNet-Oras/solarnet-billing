<?php

namespace Tests\Unit;

use App\Services\RouterSecurityBaselineAnalyzer;
use Tests\TestCase;

class RouterSecurityBaselineAnalyzerTest extends TestCase
{
    public function test_it_reports_only_observed_controls_from_a_read_only_inventory(): void
    {
        $analysis = (new RouterSecurityBaselineAnalyzer())->analyze([
            'filters' => [
                ['chain' => 'input', 'action' => 'accept', 'connection-state' => 'established,related'],
                ['chain' => 'input', 'action' => 'drop', 'connection-state' => 'invalid'],
                ['chain' => 'input', 'action' => 'drop', 'in-interface-list' => 'WAN'],
                ['chain' => 'forward', 'action' => 'drop', 'connection-state' => 'invalid'],
                ['chain' => 'forward', 'action' => 'drop', 'comment' => 'SolarNet Threat Feed: manual block outbound'],
            ],
            'nat' => [['chain' => 'srcnat', 'action' => 'masquerade']],
            'address_lists' => [['list' => 'solarnet_threat_feed', 'address' => '198.51.100.21']],
            'services' => [['name' => 'api-ssl', 'port' => '8729', 'address' => '10.99.0.0/24']],
            'interface_lists' => [['name' => 'WAN']],
            'interface_list_members' => [['list' => 'WAN', 'interface' => 'ether1']],
            'wireguard' => [['name' => 'wg-management', 'listen-port' => '13231']],
            'ipv6_filters' => [
                ['chain' => 'input', 'action' => 'drop'],
                ['chain' => 'forward', 'action' => 'drop'],
            ],
            'ipv6_addresses' => [['address' => '2001:db8:1::1/64']],
        ]);

        $checks = collect($analysis['checks'])->keyBy('id');

        $this->assertSame('ready', $analysis['summary']['status']);
        $this->assertSame('pass', $checks['input_invalid_drop']['status']);
        $this->assertSame('pass', $checks['management_service_api_ssl']['status']);
        $this->assertSame('pass', $checks['ipv6_firewall']['status']);
        $this->assertSame(1, $analysis['inventory']['solarnet_threat_list_entries']);
        $this->assertStringContainsString('read-only print commands only', $analysis['safety']);
    }

    public function test_it_flags_unrestricted_legacy_management_and_uncovered_ipv6_without_claiming_a_fix(): void
    {
        $analysis = (new RouterSecurityBaselineAnalyzer())->analyze([
            'filters' => [],
            'nat' => [],
            'address_lists' => [],
            'services' => [
                ['name' => 'telnet', 'port' => '23', 'address' => ''],
                ['name' => 'winbox', 'port' => '8291', 'address' => ''],
            ],
            'interface_lists' => [],
            'interface_list_members' => [],
            'wireguard' => [],
            'ipv6_filters' => [],
            'ipv6_addresses' => [['address' => '2001:db8:2::1/64']],
        ]);

        $checks = collect($analysis['checks'])->keyBy('id');

        $this->assertSame('needs_review', $analysis['summary']['status']);
        $this->assertSame('high', $checks['management_service_telnet']['status']);
        $this->assertSame('attention', $checks['management_service_winbox']['status']);
        $this->assertSame('high', $checks['ipv6_firewall']['status']);
        $this->assertStringContainsString('maintenance window', $checks['input_invalid_drop']['recommendation']);
        $this->assertStringContainsString('No RouterOS configuration was changed.', $analysis['safety']);
    }
}
