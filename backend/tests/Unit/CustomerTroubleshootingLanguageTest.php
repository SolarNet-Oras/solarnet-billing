<?php

namespace Tests\Unit;

use App\Services\CustomerTroubleshootingService;
use Tests\TestCase;

class CustomerTroubleshootingLanguageTest extends TestCase
{
    public function test_it_understands_filipino_led_descriptions_without_unsafe_advice(): void
    {
        $service = app(CustomerTroubleshootingService::class);
        $method = new \ReflectionMethod($service, 'parseLeds');
        $method->setAccessible(true);

        $leds = $method->invoke($service, 'Pula yung LOS pero PON ay berde.');

        $this->assertSame('red', $leds['los']);
        $this->assertSame('on', $leds['pon']);
    }

    public function test_it_accepts_filipino_positive_and_negative_followups(): void
    {
        $service = app(CustomerTroubleshootingService::class);
        $positive = new \ReflectionMethod($service, 'isPositive');
        $negative = new \ReflectionMethod($service, 'isNegative');
        $positive->setAccessible(true);
        $negative->setAccessible(true);

        $this->assertTrue($positive->invoke($service, 'Opo, gumagana na po.'));
        $this->assertTrue($negative->invoke($service, 'Hindi pa rin po nakakonekta.'));
    }
}
