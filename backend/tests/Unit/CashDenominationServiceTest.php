<?php

namespace Tests\Unit;

use App\Services\CashDenominationService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CashDenominationServiceTest extends TestCase
{
    #[Test]
    public function it_normalizes_the_requested_bill_and_coin_count(): void
    {
        $rows = app(CashDenominationService::class)->normalize([
            ['denomination' => 100, 'count' => 2],
            ['denomination' => 50, 'count' => 2],
            ['denomination' => 20, 'count' => 10],
            ['denomination' => 5, 'count' => 429],
            ['denomination' => 1, 'count' => 204],
        ]);

        $this->assertSame(2849, collect($rows)->sum('amount'));
        $this->assertSame('bill', collect($rows)->firstWhere('denomination', 20)['kind']);
        $this->assertSame('coin', collect($rows)->firstWhere('denomination', 10)['kind']);
    }

    #[Test]
    public function a_cash_movement_must_equal_its_denomination_total(): void
    {
        $service = app(CashDenominationService::class);
        $service->assertEqualsAmount($service->normalize([
            ['denomination' => 500, 'count' => 2],
        ]), 1000);
        $this->expectException(ValidationException::class);
        $service->assertEqualsAmount($service->normalize([
            ['denomination' => 500, 'count' => 1],
        ]), 1000);
    }
}
