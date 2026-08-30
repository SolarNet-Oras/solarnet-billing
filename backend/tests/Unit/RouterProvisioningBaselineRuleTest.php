<?php

namespace Tests\Unit;

use App\Services\MikrotikService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RouterProvisioningBaselineRuleTest extends TestCase
{
    public function test_standard_source_nat_masquerade_is_accepted_as_connectivity_baseline(): void
    {
        $this->assertTrue($this->baselineNat([
            'chain' => 'srcnat',
            'action' => 'masquerade',
            'out-interface-list' => 'WAN',
            'disabled' => 'false',
        ]));
    }

    public function test_port_forward_is_not_accepted_as_connectivity_baseline(): void
    {
        $this->assertFalse($this->baselineNat([
            'chain' => 'dstnat',
            'action' => 'dst-nat',
            'protocol' => 'tcp',
            'dst-port' => '443',
            'to-addresses' => '192.168.88.10',
            'disabled' => 'false',
        ]));
    }

    public function test_tcp_input_allow_for_enabled_api_port_is_accepted(): void
    {
        $method = new ReflectionMethod(MikrotikService::class, 'isBaselineApiFirewallRule');
        $this->assertTrue($method->invoke(null, [
            'chain' => 'input',
            'action' => 'accept',
            'protocol' => 'tcp',
            'dst-port' => '8728',
            'src-address' => '187.77.153.68',
            'disabled' => 'false',
        ], ['8728']));
    }

    public function test_single_coherent_private_bridge_dhcp_is_preserved_without_relying_on_names(): void
    {
        $method = new ReflectionMethod(MikrotikService::class, 'isFactoryDhcpBaseline');
        $servers = [['name' => 'defconf', 'interface' => 'bridge', 'address-pool' => 'default-dhcp', 'disabled' => 'false']];
        $pools = [['name' => 'default-dhcp', 'ranges' => '192.168.88.10-192.168.88.254']];
        $networks = [['address' => '192.168.88.0/24', 'gateway' => '192.168.88.1', 'comment' => 'defconf']];
        $bridges = [['name' => 'bridge']];

        $this->assertTrue($method->invoke(null, $servers, $pools, $networks, $bridges));
        $servers[0]['name'] = 'production-customer-dhcp';
        $networks[0]['comment'] = '';
        $pools[0]['name'] = 'production-pool';
        $servers[0]['address-pool'] = 'production-pool';
        $this->assertTrue($method->invoke(null, $servers, $pools, $networks, $bridges));
        $servers[0]['address-pool'] = 'wrong-pool';
        $this->assertFalse($method->invoke(null, $servers, $pools, $networks, $bridges));

        $servers[0]['address-pool'] = 'production-pool';
        $pools[] = ['name' => 'unused-pool', 'ranges' => '10.10.10.2-10.10.10.20'];
        $this->assertTrue($method->invoke(null, $servers, $pools, $networks, $bridges));
        $servers[] = ['name' => 'second-active', 'interface' => 'bridge', 'address-pool' => 'unused-pool', 'disabled' => 'false'];
        $this->assertFalse($method->invoke(null, $servers, $pools, $networks, $bridges));
    }

    public function test_management_allow_is_accepted_only_on_a_real_vpn_interface(): void
    {
        $method = new ReflectionMethod(MikrotikService::class, 'isBaselineVpnManagementRule');
        $rule = ['chain' => 'input', 'action' => 'accept', 'in-interface' => 'RemoteVPN', 'comment' => 'Allow Remote Winbox', 'disabled' => 'false'];

        $this->assertTrue($method->invoke(null, $rule, [['name' => 'RemoteVPN', 'type' => 'sstp-out', 'disabled' => 'false']]));
        $this->assertFalse($method->invoke(null, $rule, [['name' => 'RemoteVPN', 'type' => 'ether', 'disabled' => 'false']]));
    }

    public function test_only_an_exact_solarnet_billing_rule_is_accepted(): void
    {
        $method = new ReflectionMethod(MikrotikService::class, 'isBaselineBillingFirewallRule');
        $rule = [
            'chain' => 'forward',
            'action' => 'accept',
            'src-address-list' => 'suspended_customers',
            'protocol' => 'udp',
            'dst-port' => '53',
            'comment' => 'Solarnet Billing: suspended allow DNS UDP',
            'disabled' => 'false',
        ];

        $this->assertTrue($method->invoke(null, $rule));
        $rule['dst-port'] = '1-65535';
        $this->assertFalse($method->invoke(null, $rule));
    }

    private function baselineNat(array $rule): bool
    {
        $method = new ReflectionMethod(MikrotikService::class, 'isBaselineMasqueradeNat');
        return $method->invoke(null, $rule);
    }
}
