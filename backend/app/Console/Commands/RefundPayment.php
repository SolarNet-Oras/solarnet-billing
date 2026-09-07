<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentRefundService;
use Illuminate\Console\Command;

class RefundPayment extends Command
{
    protected $signature = 'payments:refund {payment_number} {--amount=} {--reason=} {--dry-run} {--confirm=}';
    protected $description = 'Record an audited customer refund and reverse its invoice allocation';

    public function handle(PaymentRefundService $refunds): int
    {
        $payment = Payment::with(['customer', 'invoice', 'allocations.invoice', 'refunds'])
            ->where('payment_number', $this->argument('payment_number'))->firstOrFail();
        $refunded = (float) $payment->refunds->sum('amount');
        $amount = (float) ($this->option('amount') ?: ((float) $payment->amount - $refunded));

        $this->table(['Payment', 'Customer', 'Received', 'Already refunded', 'Refund now', 'Invoice'], [[
            $payment->payment_number, $payment->customer?->full_name, number_format((float) $payment->amount, 2),
            number_format($refunded, 2), number_format($amount, 2), $payment->invoice?->invoice_number,
        ]]);

        if ($this->option('dry-run')) {
            $this->info('Preview only. No allocation, invoice, remittance, payment, or cash ledger record was changed.');
            return self::SUCCESS;
        }
        if ($this->option('confirm') !== 'RECORD CUSTOMER REFUND') {
            $this->error('Refused. Pass --confirm="RECORD CUSTOMER REFUND" after verifying cash was returned.');
            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        if ($reason === '') {
            $this->error('A truthful --reason is required for the audit trail.');
            return self::FAILURE;
        }

        $refund = $refunds->refund($payment, $amount, $reason);
        $invoice = $refund->payment->invoice->fresh();
        $this->info("Refund recorded. {$invoice->invoice_number} now has paid {$invoice->paid_amount}, balance {$invoice->balance}, status {$invoice->status}.");
        $this->info('The original payment and received remittance were preserved for audit.');
        return self::SUCCESS;
    }
}
