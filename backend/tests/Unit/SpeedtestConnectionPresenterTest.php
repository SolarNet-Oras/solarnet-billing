<?php

namespace Tests\Unit;

use App\Services\SpeedtestConnectionPresenter;
use PHPUnit\Framework\TestCase;

class SpeedtestConnectionPresenterTest extends TestCase
{
    public function test_it_preserves_a_real_ipv4_address_and_uses_the_brand_name(): void
    {
        $result = (new SpeedtestConnectionPresenter())->present('49.146.229.169', 'SolarNet Internet');

        self::assertSame('49.146.229.169', $result['public_ip']);
        self::assertSame('SolarNet Internet', $result['provider_display_name']);
    }

    public function test_it_supports_ipv6_without_forcing_ipv4(): void
    {
        $result = (new SpeedtestConnectionPresenter())->present('2001:db8:85a3::8a2e:370:7334', 'SolarNet Internet');

        self::assertSame('2001:db8:85a3::8a2e:370:7334', $result['public_ip']);
        self::assertSame('SolarNet Internet', $result['provider_display_name']);
    }

    public function test_it_never_invents_an_ip_and_keeps_branding_separate_from_network_details(): void
    {
        $result = (new SpeedtestConnectionPresenter())->present('not-an-ip', 'SolarNet Broadband');

        self::assertNull($result['public_ip']);
        self::assertSame('SolarNet Broadband', $result['provider_display_name']);
        self::assertNull($result['detected_isp']);
    }

    public function test_each_connection_payload_keeps_its_own_current_ip(): void
    {
        $presenter = new SpeedtestConnectionPresenter();
        $first = $presenter->present('49.146.229.169', 'SolarNet Internet');
        $second = $presenter->present('49.146.230.25', 'SolarNet Internet');

        self::assertSame('49.146.229.169', $first['public_ip']);
        self::assertSame('49.146.230.25', $second['public_ip']);
        self::assertNotSame($first['public_ip'], $second['public_ip']);
    }
}
