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

    public function test_it_normalizes_ipinfo_lite_ownership_data_without_exposing_the_token(): void
    {
        config()->set('services.ip_intelligence.base_url', 'https://api.ipinfo.io/lite');
        config()->set('services.ip_intelligence.token', 'test-token');
        Http::fake(['https://api.ipinfo.io/lite/*' => Http::response([
            'ip' => '8.8.8.8',
            'asn' => 'AS15169',
            'as_name' => 'Google LLC',
            'as_domain' => 'google.com',
            'country_code' => 'US',
            'country' => 'United States',
            'continent_code' => 'NA',
            'continent' => 'North America',
        ], 200)]);

        $result = app(IpIntelligenceService::class)->lookup('8.8.8.8');

        $this->assertSame('AS15169', $result['asn']);
        $this->assertSame('Google LLC', $result['as_name']);
        $this->assertSame('google.com', $result['domain']);
        $this->assertSame('North America', $result['continent']);
        $this->assertNull($result['hosting']);
        $this->assertSame('unavailable', $result['classification']);
        $this->assertArrayNotHasKey('token', $result);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer test-token'));
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
