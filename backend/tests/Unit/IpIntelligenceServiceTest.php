<?php

namespace Tests\Unit;

use App\Services\IpIntelligenceService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class IpIntelligenceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_normalizes_network_and_hosting_intelligence(): void
    {
        Http::fake(['https://ipwho.is/*' => Http::response([
            'success' => true,
            'ip' => '8.8.8.8',
            'type' => 'IPv4',
            'country' => 'United States',
            'country_code' => 'US',
            'region' => 'California',
            'city' => 'Mountain View',
            'latitude' => 37.4,
            'longitude' => -122.1,
            'connection' => ['asn' => 15169, 'isp' => 'Google', 'org' => 'Google LLC', 'domain' => 'google.com', 'type' => 'Hosting'],
            'security' => ['hosting' => true, 'proxy' => false, 'vpn' => false, 'tor' => false, 'anonymous' => false],
        ], 200)]);

        $result = app(IpIntelligenceService::class)->lookup('8.8.8.8');

        $this->assertSame('AS15169', $result['asn']);
        $this->assertSame('Google', $result['isp']);
        $this->assertTrue($result['hosting']);
        $this->assertSame('hosting', $result['classification']);
    }

    public function test_private_addresses_are_never_sent_to_provider(): void
    {
        Http::fake();
        $this->expectException(ValidationException::class);

        try {
            app(IpIntelligenceService::class)->lookup('192.168.1.1');
        } finally {
            Http::assertNothingSent();
        }
    }
}
