<?php

namespace Tests\Unit;

use App\Services\RouterQosModeAnalyzer;
use PHPUnit\Framework\TestCase;

class RouterQosModeAnalyzerTest extends TestCase
{
    public function test_multiple_vlan_clients_block_only_global_full_qos_and_keep_safe_qos_available(): void
    {
        $analysis = (new RouterQosModeAnalyzer())->analyze($this->inspection([
            'client_interfaces' => ['vlan1030', 'vlan1050'],
            'existing_queues' => ['billing_customer_queues' => 48, 'other_simple_queues' => 0, 'solarnet_qos_trees' => 0],
        ]));

        $this->assertSame('safe', $analysis['recommended_mode']);
        $this->assertFalse($analysis['full']['available']);
        $this->assertTrue($analysis['safe']['available']);
        $this->assertStringContainsString('multiple client-facing', implode(' ', $analysis['full']['reasons']));
    }

    public function test_safe_qos_is_not_offered_without_a_solar_net_queue_or_fq_codel(): void
    {
        $analysis = (new RouterQosModeAnalyzer())->analyze($this->inspection([
            'existing_queues' => ['billing_customer_queues' => 0, 'other_simple_queues' => 2, 'solarnet_qos_trees' => 0],
            'queue_capabilities' => ['fq_codel' => [], 'pcq' => []],
        ]));

        $this->assertSame('disabled', $analysis['recommended_mode']);
        $this->assertFalse($analysis['safe']['available']);
        $this->assertStringContainsString('No SolarNet-managed', $analysis['disabled']['reason']);
        $this->assertStringContainsString('Sync the customer from Billing', implode(' ', $analysis['safe']['suggestions']));

        $withoutFqCodel = $this->inspection();
        $withoutFqCodel['queue_capabilities']['fq_codel'] = [];
        $fqCodelAnalysis = (new RouterQosModeAnalyzer())->analyze($withoutFqCodel);
        $this->assertFalse($fqCodelAnalysis['safe']['available']);
        $this->assertStringContainsString('maintenance window', implode(' ', $fqCodelAnalysis['safe']['suggestions']));
    }

    private function inspection(array $overrides = []): array
    {
        return array_replace_recursive([
            'cpu_load' => 20,
            'interfaces' => [
                ['name' => 'ether1-wan', 'running' => true, 'disabled' => false],
                ['name' => 'vlan1030', 'running' => true, 'disabled' => false],
                ['name' => 'vlan1050', 'running' => true, 'disabled' => false],
            ],
            'client_interfaces' => ['vlan1030'],
            'wan_candidates' => [['interface' => 'ether1-wan']],
            'multi_wan_detected' => false,
            'fasttrack' => ['enabled' => false],
            'mangle_rule_count' => 0,
            'existing_queues' => ['billing_customer_queues' => 1, 'other_simple_queues' => 0, 'solarnet_qos_trees' => 0],
            'queue_capabilities' => ['fq_codel' => ['fq-codel'], 'pcq' => []],
        ], $overrides);
    }
}
