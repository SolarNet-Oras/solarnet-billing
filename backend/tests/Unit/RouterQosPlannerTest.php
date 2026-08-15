<?php

namespace Tests\Unit;

use App\Services\RouterQosPlanner;
use PHPUnit\Framework\TestCase;

class RouterQosPlannerTest extends TestCase
{
    public function test_safe_router_preview_selects_fq_codel_and_preserves_customer_queue_count(): void
    {
        $plan = (new RouterQosPlanner())->plan($this->inspection(), $this->input());

        $this->assertTrue($plan['ready']);
        $this->assertSame('fq_codel_interface_tree', $plan['recommendation']['strategy']);
        $this->assertSame('default-fq-codel', $plan['recommendation']['queue_type']);
        $this->assertSame('950M', $plan['configuration']['download_limit']);
        $this->assertSame('95M', $plan['configuration']['upload_limit']);
        $this->assertSame(842, $plan['preservation']['customer_simple_queues_preserved']);
        $this->assertSame(0, $plan['preservation']['firewall_rules_changed']);
        $this->assertSame(0, $plan['preservation']['mangle_rules_changed']);
    }

    public function test_fasttrack_is_refused_without_modifying_the_administrator_rule(): void
    {
        $inspection = $this->inspection();
        $inspection['fasttrack'] = ['enabled' => true, 'count' => 1];

        $plan = (new RouterQosPlanner())->plan($inspection, $this->input());

        $this->assertFalse($plan['ready']);
        $this->assertStringContainsString('FastTrack', implode(' ', $plan['errors']));
        $this->assertSame(0, $plan['preservation']['queue_trees_to_create']);
    }

    public function test_multi_wan_requires_a_positively_identified_wan_interface(): void
    {
        $inspection = $this->inspection();
        $inspection['multi_wan_detected'] = true;
        $inspection['wan_candidates'] = [['gateway' => '10.0.0.1', 'interface' => null, 'distance' => '1', 'routing_table' => 'main']];

        $plan = (new RouterQosPlanner())->plan($inspection, $this->input());

        $this->assertFalse($plan['ready']);
        $this->assertStringContainsString('Multiple WAN', implode(' ', $plan['errors']));
    }

    public function test_unsupported_queue_types_are_refused_with_no_guessing(): void
    {
        $inspection = $this->inspection();
        $inspection['queue_capabilities'] = ['cake' => [], 'fq_codel' => [], 'pcq' => []];

        $plan = (new RouterQosPlanner())->plan($inspection, $this->input());

        $this->assertFalse($plan['ready']);
        $this->assertStringContainsString('Neither FQ-CoDel nor PCQ', implode(' ', $plan['errors']));
    }

    public function test_high_cpu_and_interface_wide_test_mode_are_refused(): void
    {
        $inspection = $this->inspection();
        $inspection['cpu_load'] = 81;
        $input = $this->input();
        $input['mode'] = 'test';

        $plan = (new RouterQosPlanner())->plan($inspection, $input);

        $this->assertFalse($plan['ready']);
        $this->assertStringContainsString('CPU', implode(' ', $plan['errors']));
        $this->assertStringContainsString('Test mode', implode(' ', $plan['errors']));
    }

    private function input(): array
    {
        return [
            'download_capacity_mbps' => 1000,
            'upload_capacity_mbps' => 100,
            'ceiling_percent' => 95,
            'download_parent' => 'bridge-clients',
            'upload_parent' => 'ether1-wan',
            'mode' => 'production',
        ];
    }

    private function inspection(): array
    {
        return [
            'cpu_load' => 24,
            'interfaces' => [
                ['name' => 'bridge-clients'],
                ['name' => 'ether1-wan'],
            ],
            'warnings' => [],
            'fasttrack' => ['enabled' => false, 'count' => 0],
            'existing_queues' => [
                'billing_customer_queues' => 842,
                'other_simple_queues' => 5,
                'solarnet_qos_trees' => 0,
            ],
            'queue_capabilities' => [
                'cake' => ['cake-default'],
                'fq_codel' => ['default-fq-codel'],
                'pcq' => ['pcq-download-default'],
            ],
            'multi_wan_detected' => false,
            'wan_candidates' => [['gateway' => 'ether1-wan', 'interface' => 'ether1-wan', 'distance' => '1', 'routing_table' => 'main']],
        ];
    }
}
