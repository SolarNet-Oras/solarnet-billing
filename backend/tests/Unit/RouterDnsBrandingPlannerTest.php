<?php

namespace Tests\Unit;

use App\Services\RouterDnsBrandingPlanner;
use PHPUnit\Framework\TestCase;

class RouterDnsBrandingPlannerTest extends TestCase
{
    public function test_plan_adds_only_new_solarnet_record_and_explicit_dhcp_network(): void
    {
        $result = (new RouterDnsBrandingPlanner())->build($this->discovery(), [
            'domain' => 'solarnet.local',
            'records' => [['hostname' => 'router', 'type' => 'A', 'address' => '192.168.88.1', 'ttl' => 86400, 'description' => 'Router']],
            'approved_dhcp_network_ids' => ['*10'],
            'remove_record_ids' => [],
        ]);

        self::assertTrue($result['success']);
        self::assertSame('router.solarnet.local', $result['data']['record_changes'][0]['hostname']);
        self::assertSame('add_solarnet', $result['data']['record_changes'][0]['action']);
        self::assertSame('192.168.50.1', $result['data']['dhcp_changes'][0]['new_dns_server']);
        self::assertSame(1, $result['data']['protected']['unknown_static_records']);
        self::assertFalse($result['data']['protected']['wan_changed']);
    }

    public function test_protected_custom_record_is_never_overwritten(): void
    {
        $discovery = $this->discovery();
        $discovery['static_records'][] = [
            'id' => '*99', 'name' => 'router.solarnet.local', 'address' => '192.168.50.254',
            'type' => 'A', 'ttl' => '1d', 'comment' => 'Administrator custom record', 'owned_by_solarnet' => false,
        ];
        $result = (new RouterDnsBrandingPlanner())->build($discovery, [
            'domain' => 'solarnet.local',
            'records' => [['hostname' => 'router', 'type' => 'A', 'address' => '192.168.88.1', 'ttl' => 86400, 'description' => 'Router']],
            'approved_dhcp_network_ids' => [],
            'remove_record_ids' => [],
        ]);

        self::assertFalse($result['success']);
        self::assertStringContainsString('protected DNS record', $result['message']);
    }

    public function test_dhcp_distribution_is_refused_when_remote_requests_are_disabled(): void
    {
        $discovery = $this->discovery(['allow_remote_requests' => false]);
        $result = (new RouterDnsBrandingPlanner())->build($discovery, [
            'domain' => 'lan.solarnetconnection.com',
            'records' => [['hostname' => 'billing', 'type' => 'A', 'address' => '192.168.50.10', 'ttl' => 3600, 'description' => 'Billing']],
            'approved_dhcp_network_ids' => ['*10'],
            'remove_record_ids' => [],
        ]);

        self::assertFalse($result['success']);
        self::assertStringContainsString('remote requests are currently disabled', $result['message']);
    }

    private function discovery(array $overrides = []): array
    {
        return array_replace_recursive([
            'allow_remote_requests' => true,
            'upstream_dns_available' => true,
            'static_records' => [[
                'id' => '*01', 'name' => 'printer.solarnet.local', 'address' => '192.168.50.5',
                'type' => 'A', 'ttl' => '1d', 'comment' => 'Office printer', 'owned_by_solarnet' => false,
            ]],
            'dhcp_networks' => [[
                'id' => '*10', 'server_name' => 'dhcp-vlan1050', 'interface' => 'vlan1050',
                'network' => '192.168.50.0/24', 'gateway' => '192.168.50.1',
                'dns_server' => '8.8.8.8', 'manageable' => true,
            ]],
        ], $overrides);
    }
}
