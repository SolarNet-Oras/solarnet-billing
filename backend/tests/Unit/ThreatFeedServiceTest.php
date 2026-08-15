<?php

namespace Tests\Unit;

use App\Services\MikrotikService;
use App\Services\ThreatFeedService;
use PHPUnit\Framework\TestCase;

class ThreatFeedServiceTest extends TestCase
{
    public function test_parser_keeps_only_valid_ipv4_indicators(): void
    {
        $service = new ThreatFeedService($this->createMock(MikrotikService::class));

        $this->assertSame([
            '1.2.3.4' => true,
            '8.8.8.8' => true,
        ], $service->parseIndicators("# comment\n1.2.3.4\ninvalid\n8.8.8.8 # note\n2001:db8::1\n"));
    }
}
