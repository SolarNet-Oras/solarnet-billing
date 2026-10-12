<?php

namespace Tests\Feature;

use App\Models\FinancialEntry;
use App\Models\PaymongoOutwardTransfer;
use App\Services\PaymongoOutwardTransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymongoOutwardTransferLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_send_funds_posts_one_detailed_daily_operation(): void
    {
        $resource = $this->resource('succeeded');
        $service = app(PaymongoOutwardTransferService::class);

        $service->receive($resource, 'evt_transfer_success', 'transfer.outward.successful');
        $service->receive($resource, 'evt_transfer_success', 'transfer.outward.successful');

        $this->assertDatabaseCount('paymongo_outward_transfers', 1);
        $this->assertDatabaseCount('financial_entries', 1);

        $entry = FinancialEntry::sole();
        $this->assertSame(1025.50, $entry->amount);
        $this->assertSame(25.50, $entry->provider_fee);
        $this->assertSame('paymongo', $entry->source_wallet);
        $this->assertSame('Juan Dela Cruz', $entry->external_recipient_name);
        $this->assertSame('GCash', $entry->external_recipient_institution);
        $this->assertSame('7890', $entry->external_recipient_account_last4);
        $this->assertSame('tr_test_123', $entry->provider_transaction_id);
        $this->assertSame($entry->id, PaymongoOutwardTransfer::sole()->financial_entry_id);
    }

    public function test_pending_or_failed_transfer_does_not_reduce_daily_operations(): void
    {
        $service = app(PaymongoOutwardTransferService::class);
        $service->receive($this->resource('pending'), 'evt_pending', 'transfer.outward.created');
        $service->receive($this->resource('failed'), 'evt_failed', 'transfer.outward.failed');

        $this->assertDatabaseCount('paymongo_outward_transfers', 1);
        $this->assertDatabaseCount('financial_entries', 0);
        $this->assertSame('failed', PaymongoOutwardTransfer::sole()->status);
    }

    private function resource(string $status): array
    {
        return [
            'id' => 'tr_test_123',
            'attributes' => [
                'status' => $status,
                'amount' => 100000,
                'fee' => 2550,
                'currency' => 'PHP',
                'reference_number' => 'SOLARNET-001',
                'provider_reference_number' => 'PM-REFERENCE-001',
                'provider' => 'instapay',
                'receiver' => [
                    'bank_account_name' => 'Juan Dela Cruz',
                    'bank_account_number' => '09171237890',
                    'bank_name' => 'GCash',
                ],
                'created_at' => 1791770400,
                'updated_at' => 1791770460,
            ],
        ];
    }
}
