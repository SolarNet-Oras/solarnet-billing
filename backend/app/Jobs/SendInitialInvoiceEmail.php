<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendInitialInvoiceEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // First attempt plus one automatic retry.
    public int $tries = 2;
    public array $backoff = [60];
    public int $timeout = 60;

    public function __construct(public string $invoiceId)
    {
    }

    public function handle(InvoiceService $invoices): void
    {
        $invoice = Invoice::with(['customer', 'items', 'payments'])->find($this->invoiceId);
        if (!$invoice) {
            return;
        }

        if ($invoices->sendInitialInvoiceEmail($invoice) === 'failed') {
            throw new RuntimeException('Initial invoice email was not accepted; retrying once.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Invoice::query()
            ->whereKey($this->invoiceId)
            ->whereNull('initial_email_sent_at')
            ->update([
                'initial_email_status' => 'failed',
                'initial_email_failure_reason' => substr((string) $exception?->getMessage(), 0, 500),
            ]);
    }
}
