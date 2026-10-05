<?php

namespace Tests\Unit;

use App\Models\Invoice;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InvoiceCollectorEligibilityTest extends TestCase
{
    #[DataProvider('invoiceStates')]
    public function test_only_unpaid_due_or_overdue_invoices_are_collectible(
        string $status,
        float $balance,
        string $dueDate,
        bool $expected,
    ): void {
        $invoice = new Invoice([
            'status' => $status,
            'balance' => $balance,
            'due_date' => $dueDate,
        ]);

        $this->assertSame(
            $expected,
            $invoice->isDueAndCollectible(Carbon::parse('2026-10-05', 'Asia/Manila')),
        );
    }

    public static function invoiceStates(): array
    {
        return [
            'due today' => ['sent', 800, '2026-10-05', true],
            'overdue' => ['overdue', 800, '2026-10-04', true],
            'partial overdue' => ['partial', 200, '2026-10-01', true],
            'future' => ['sent', 800, '2026-10-06', false],
            'paid status' => ['paid', 800, '2026-10-01', false],
            'zero balance' => ['overdue', 0, '2026-10-01', false],
            'cancelled' => ['cancelled', 800, '2026-10-01', false],
            'draft' => ['draft', 800, '2026-10-01', false],
        ];
    }
}
