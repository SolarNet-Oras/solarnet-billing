<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\ServicePlan;
use App\Services\DhcpSyncService;
use PHPUnit\Framework\TestCase;

class DhcpLeaseStaticOwnershipTest extends TestCase
{
    public function test_routeros_ownership_comment_includes_account_and_customer_name(): void
    {
        $customer = new Customer([
            'account_number' => '9981453309',
            'full_name' => 'Ralph Aculana',
        ]);

        $this->assertSame(
            'SolarNet | 9981453309 | Ralph Aculana',
            $this->invoke('customerLeaseComment', $customer),
        );
    }

    public function test_sync_all_never_staticizes_a_comment_only_or_partial_mac_match(): void
    {
        $customer = new Customer([
            'status' => 'active',
            'mac_address' => '88:65:9F:97:D0:41',
        ]);
        $lease = new DhcpLease([
            'mac_address' => '88:65:9F:97:D0:41',
            'match_source' => 'account_comment',
        ]);

        $result = $this->invoke('ensureRegisteredLeaseIsStatic', $customer, $lease);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['attempted']);
        $this->assertFalse($result['lease_static']);
        $this->assertStringContainsString('full exact customer MAC match', $result['message']);
    }

    public function test_bounded_maintenance_accepts_only_an_exact_current_registered_lease_that_needs_work(): void
    {
        $customer = $this->customerForMaintenance();
        $lease = new DhcpLease([
            'router_id' => 'router-1',
            'mac_address' => '88:65:9F:97:D0:41',
            'is_current' => true,
            'is_matched' => true,
            'is_dynamic' => true,
            'status' => 'bound',
            'match_source' => 'mac_address',
        ]);

        $this->assertTrue($this->invoke('needsRegisteredLeaseStaticEnforcement', $customer, $lease));

        $lease->is_dynamic = false;
        $lease->comment = 'SolarNet | 9981453309 | Ralph Aculana';
        $lease->rate_limit = '50M/50M';
        $this->assertFalse($this->invoke('needsRegisteredLeaseStaticEnforcement', $customer, $lease));
    }

    public function test_bounded_maintenance_refuses_a_different_router_or_non_exact_match(): void
    {
        $customer = $this->customerForMaintenance();
        $lease = new DhcpLease([
            'router_id' => 'router-2',
            'mac_address' => '88:65:9F:97:D0:41',
            'is_current' => true,
            'is_matched' => true,
            'is_dynamic' => true,
            'status' => 'bound',
            'match_source' => 'mac_address',
        ]);

        $this->assertFalse($this->invoke('needsRegisteredLeaseStaticEnforcement', $customer, $lease));

        $lease->router_id = 'router-1';
        $lease->match_source = 'account_comment';
        $this->assertFalse($this->invoke('needsRegisteredLeaseStaticEnforcement', $customer, $lease));
    }

    private function customerForMaintenance(): Customer
    {
        $customer = new Customer([
            'account_number' => '9981453309',
            'full_name' => 'Ralph Aculana',
            'status' => 'active',
            'router_id' => 'router-1',
            'mac_address' => '88:65:9F:97:D0:41',
            'queue_synced' => true,
        ]);
        $customer->setRelation('servicePlan', new ServicePlan([
            'download_speed' => 50,
            'upload_speed' => 50,
        ]));

        return $customer;
    }

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionClass(DhcpSyncService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $target = $reflection->getMethod($method);

        return $target->invoke($service, ...$arguments);
    }
}
