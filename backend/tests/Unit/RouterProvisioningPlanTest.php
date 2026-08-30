<?php

namespace Tests\Unit;

use App\Services\MikrotikService;
use App\Services\RouterProvisioningService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RouterProvisioningPlanTest extends TestCase
{
    public function test_clean_router_plan_is_ipoe_only_and_creates_no_customer_queue(): void
    {
        $plan = $this->plan($this->cleanDiscovery(), $this->input());

        $this->assertTrue($plan['success']);
        $this->assertSame('IPoE ONLY', $plan['data']['access']);
        $this->assertSame('NOT USED', $plan['data']['pppoe']);
        $this->assertSame('safe_compatible', $plan['data']['qos_mode']);
        $this->assertFalse($plan['data']['captive_portal']['enabled']);
        $this->assertStringContainsString('customer Simple Queues are created later by Billing', implode(' ', $plan['data']['planned_changes']));
    }

    public function test_dirty_router_is_refused_without_a_plan(): void
    {
        $discovery = $this->cleanDiscovery();
        $discovery['clean'] = false;
        $discovery['blockers'] = ['Existing DHCP server configuration was detected.'];

        $plan = $this->plan($discovery, $this->input());

        $this->assertFalse($plan['success']);
        $this->assertStringContainsString('ROUTER IS NOT CLEAN', $plan['message']);
    }

    public function test_plan_requires_separate_confirmed_wan_and_customer_parent(): void
    {
        $input = $this->input();
        $input['customer_parent_interface'] = 'ether1-wan';

        $plan = $this->plan($this->cleanDiscovery(), $input);

        $this->assertFalse($plan['success']);
        $this->assertStringContainsString('different enabled physical Ethernet interface', $plan['message']);
    }

    public function test_disconnected_enabled_ethernet_can_be_selected_as_customer_parent(): void
    {
        $discovery = $this->cleanDiscovery();
        $discovery['running_interfaces'] = ['ether1-wan'];

        $plan = $this->plan($discovery, $this->input());

        $this->assertTrue($plan['success']);
        $this->assertSame('ether2-trunk', $plan['data']['customer_parent_interface']);
    }

    public function test_plan_preserves_preexisting_verified_billing_access(): void
    {
        $discovery = $this->cleanDiscovery();
        $discovery['baseline_connectivity'] = ['billing_rules_preserved' => true];

        $plan = $this->plan($discovery, $this->input());

        $this->assertTrue($plan['success']);
        $this->assertTrue($plan['data']['preserve_existing_billing_access']);
    }

    private function plan(array $discovery, array $input): array
    {
        $service = new RouterProvisioningService($this->createMock(MikrotikService::class));
        $method = new ReflectionMethod($service, 'buildPlan');

        return $method->invoke($service, $discovery, $input);
    }

    private function cleanDiscovery(): array
    {
        return [
            'clean' => true,
            'running_interfaces' => ['ether1-wan', 'ether2-trunk'],
            'customer_parent_candidates' => ['ether1-wan', 'ether2-trunk'],
            'counts' => ['firewall_nat' => 0],
            'fq_codel_available' => true,
            'fasttrack_enabled' => false,
        ];
    }

    private function input(): array
    {
        return [
            'wan_interface' => 'ether1-wan',
            'customer_parent_interface' => 'ether2-trunk',
            'customer_vlan_id' => 100,
            'customer_gateway_cidr' => '10.100.0.1/24',
            'customer_dhcp_pool' => '10.100.0.10-10.100.0.254',
            'dns_servers' => '1.1.1.1,8.8.8.8',
            'enable_captive_portal' => false,
        ];
    }
}
