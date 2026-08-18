<?php

namespace Tests\Unit;

use App\Models\ServicePlan;
use App\Services\RadiusSubscriberService;
use Tests\TestCase;

class RadiusSubscriberServiceTest extends TestCase
{
    public function test_it_normalizes_only_complete_non_zero_mac_addresses(): void
    {
        $this->assertSame('AA:BB:CC:DD:EE:FF', RadiusSubscriberService::normalizeMac('aa-bb-cc-dd-ee-ff'));
        $this->assertSame('AA:BB:CC:DD:EE:FF', RadiusSubscriberService::normalizeMac('aabb.ccdd.eeff'));
        $this->assertNull(RadiusSubscriberService::normalizeMac('AA:BB:CC:DD:EE'));
        $this->assertNull(RadiusSubscriberService::normalizeMac('00:00:00:00:00:00'));
    }

    public function test_it_generates_routeros_rate_limit_in_customer_upload_download_order(): void
    {
        $plan = new ServicePlan(['download_speed' => 100, 'upload_speed' => 50]);
        $this->assertSame('50M/100M', RadiusSubscriberService::rateLimitFromPlan($plan));
        $this->assertNull(RadiusSubscriberService::rateLimitFromPlan(new ServicePlan(['download_speed' => 50, 'upload_speed' => 0])));
    }
}
