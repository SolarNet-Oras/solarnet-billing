<?php

namespace Tests\Unit;

use App\Services\CashTenderCalculator;
use PHPUnit\Framework\TestCase;

class CashTenderCalculatorTest extends TestCase
{
    public function test_a_one_thousand_peso_bill_covers_an_eight_hundred_peso_payment_with_two_hundred_change(): void
    {
        $calculator = new CashTenderCalculator();

        $tendered = $calculator->tenderedAmount([
            ['amount' => 1000],
        ]);

        $this->assertTrue($calculator->covers($tendered, 800));
        $this->assertSame(200.0, $calculator->change($tendered, 800));
    }

    public function test_short_cash_does_not_cover_the_payment(): void
    {
        $calculator = new CashTenderCalculator();

        $this->assertFalse($calculator->covers(500, 800));
        $this->assertSame(0.0, $calculator->change(500, 800));
    }
}
