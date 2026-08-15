<?php

namespace App\Services;

use InvalidArgumentException;

/** Pure, deterministic allocation math for a future-cycle advance payment. */
class AdvancePaymentAllocator
{
    /** @return array<int, array{amount: float, fully_covered: bool}> */
    public function allocate(float $amount, float $cycleAmount): array
    {
        if ($amount <= 0 || $cycleAmount <= 0) {
            throw new InvalidArgumentException('Advance and cycle amounts must be greater than zero.');
        }

        $allocations = [];
        $remaining = round($amount, 2);
        while ($remaining > 0) {
            $allocated = min($remaining, $cycleAmount);
            $allocations[] = [
                'amount' => $allocated,
                'fully_covered' => round($allocated, 2) >= round($cycleAmount, 2),
            ];
            $remaining = round($remaining - $allocated, 2);
        }

        return $allocations;
    }
}
