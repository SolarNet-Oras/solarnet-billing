<?php

namespace Tests\Unit;

use App\Services\HistoricalDataCleanupService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HistoricalDataCleanupModuleTest extends TestCase
{
    public function test_zero_balance_advance_cleanup_requires_linked_payment_and_invoice_cleanup(): void
    {
        $service = new HistoricalDataCleanupService();
        $method = new \ReflectionMethod($service, 'validatedModules');

        $this->assertSame(
            ['past_transactions', 'invoices', 'advance_credits'],
            $method->invoke($service, ['past_transactions', 'invoices', 'advance_credits']),
        );
    }

    public function test_zero_balance_advance_cleanup_cannot_be_selected_on_its_own(): void
    {
        $service = new HistoricalDataCleanupService();
        $method = new \ReflectionMethod($service, 'validatedModules');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Select both Past transactions and Historical invoices');

        $method->invoke($service, ['advance_credits']);
    }
}
