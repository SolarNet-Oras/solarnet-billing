<?php

namespace Tests\Unit;

use App\Services\AdvancePaymentAllocator;
use PHPUnit\Framework\TestCase;

class AdvancePaymentAllocatorTest extends TestCase
{
    public function test_full_advance_covers_one_cycle(): void
    {
        $allocations = (new AdvancePaymentAllocator())->allocate(1500, 1500);

        $this->assertSame([['amount' => 1500.0, 'fully_covered' => true]], $allocations);
    }

    public function test_partial_advance_keeps_the_unpaid_cycle_partial(): void
    {
        $allocations = (new AdvancePaymentAllocator())->allocate(500, 1500);

        $this->assertSame([['amount' => 500.0, 'fully_covered' => false]], $allocations);
    }

    public function test_multi_month_advance_reserves_each_cycle_in_order(): void
    {
        $allocations = (new AdvancePaymentAllocator())->allocate(4500, 1500);

        $this->assertCount(3, $allocations);
        $this->assertSame([1500.0, 1500.0, 1500.0], array_column($allocations, 'amount'));
        $this->assertTrue($allocations[0]['fully_covered']);
        $this->assertTrue($allocations[1]['fully_covered']);
        $this->assertTrue($allocations[2]['fully_covered']);
    }
}
