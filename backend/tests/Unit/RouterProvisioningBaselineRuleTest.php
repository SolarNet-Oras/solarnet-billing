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

    private function baselineNat(array $rule): bool
    {
        $method = new ReflectionMethod(MikrotikService::class, 'isBaselineMasqueradeNat');
        return $method->invoke(null, $rule);
    }
}
