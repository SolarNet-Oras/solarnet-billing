<?php

namespace App\Services;

/**
 * Calculates cash tender and change using cent values, avoiding float errors
 * in the payment and advance-credit workflows.
 */
class CashTenderCalculator
{
    /** @param array<int, array{amount: int|float|string}> $breakdown */
    public function tenderedAmount(array $breakdown): float
    {
        return round(array_sum(array_map(
            fn (array $line) => (float) ($line['amount'] ?? 0),
            $breakdown,
        )), 2);
    }

    public function covers(float $tenderedAmount, float $paymentAmount): bool
    {
        return $this->toCents($tenderedAmount) >= $this->toCents($paymentAmount);
    }

    public function change(float $tenderedAmount, float $paymentAmount): float
    {
        return round(max(0, $this->toCents($tenderedAmount) - $this->toCents($paymentAmount)) / 100, 2);
    }

    private function toCents(float $amount): int
    {
        return (int) round($amount * 100);
    }
}
