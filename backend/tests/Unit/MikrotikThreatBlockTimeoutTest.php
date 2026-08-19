<?php

namespace Tests\Unit;

use App\Services\MikrotikService;
use ReflectionMethod;
use Tests\TestCase;

class MikrotikThreatBlockTimeoutTest extends TestCase
{
    public function test_reviewed_threat_blocks_default_to_one_day(): void
    {
        config()->set('threat-monitor.manual_block_timeout', null);

        $this->assertSame('1d', $this->timeout());
        $this->assertSame(86_400, $this->seconds('1d'));
    }

    public function test_invalid_or_overlong_block_windows_remain_temporary(): void
    {
        config()->set('threat-monitor.manual_block_timeout', 'permanent');
        $this->assertSame('1d', $this->timeout());

        config()->set('threat-monitor.manual_block_timeout', '0h');
        $this->assertSame('1d', $this->timeout());

        config()->set('threat-monitor.manual_block_timeout', '90d');
        $this->assertSame('1w', $this->timeout());
        $this->assertSame(604_800, $this->seconds('90d'));
        $this->assertSame(86_400, $this->seconds('invalid'));
    }

    private function timeout(): string
    {
        $method = new ReflectionMethod(MikrotikService::class, 'reviewedThreatBlockTimeout');

        return $method->invoke(new MikrotikService());
    }

    private function seconds(string $value): int
    {
        $method = new ReflectionMethod(MikrotikService::class, 'reviewedThreatBlockTimeoutSeconds');

        return $method->invoke(new MikrotikService(), $value);
    }
}
