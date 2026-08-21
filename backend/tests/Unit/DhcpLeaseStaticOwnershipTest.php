<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\DhcpLease;
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

    private function invoke(string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionClass(DhcpSyncService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $target = $reflection->getMethod($method);

        return $target->invoke($service, ...$arguments);
    }
}
