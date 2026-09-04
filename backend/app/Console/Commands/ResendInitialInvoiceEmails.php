<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoiceService;
use Illuminate\Console\Command;

class ResendInitialInvoiceEmails extends Command
{
    protected $signature = 'automation:resend-initial-invoice-emails
                            {--invoice=* : Exact invoice number; repeat this option for each invoice}
                            {--all-open : Select every sent, partial, or overdue invoice with an outstanding balance}
                            {--force-resend : Send a fresh email even when an earlier invoice email was delivered}
                            {--confirm= : Required phrase for an actual all-open delivery}
                            {--dry-run : Preview recipients and eligibility without sending email}';

    protected $description = 'Preview or resend initial invoice emails for explicitly selected invoices without changing billing records';

    public function handle(InvoiceService $invoices): int
    {
        $numbers = collect($this->option('invoice'))
            ->map(fn (mixed $number): string => strtoupper(trim((string) $number)))
            ->filter()
            ->unique()
            ->values();

        $allOpen = (bool) $this->option('all-open');
        $forceResend = (bool) $this->option('force-resend');
        $dryRun = (bool) $this->option('dry-run');

        if ($allOpen && $numbers->isNotEmpty()) {
            $this->error('Choose either --all-open or explicit --invoice options, not both.');
            return self::FAILURE;
        }

        if ($numbers->isEmpty() && !$allOpen) {
            $this->error('Provide at least one exact invoice number using --invoice=INV-YYYYMM-NNNN.');

            return self::FAILURE;
        }

        if (!$allOpen && $numbers->count() > 100) {
            $this->error('Stopped: no more than 100 explicitly selected invoices may be processed at once.');

            return self::FAILURE;
        }

        if ($allOpen && !$dryRun && $this->option('confirm') !== 'RESEND ALL OPEN INVOICE EMAILS') {
            $this->error('Stopped: use --confirm="RESEND ALL OPEN INVOICE EMAILS" after reviewing --dry-run.');
            return self::FAILURE;
        }

        $query = Invoice::query()->with(['customer', 'items', 'payments']);
        if ($allOpen) {
            $selected = $query->whereIn('status', ['sent', 'partial', 'overdue'])
                ->where('balance', '>', 0)
                ->orderBy('due_date')->orderBy('invoice_number')->get();
            $numbers = $selected->pluck('invoice_number')->values();
            $found = $selected->keyBy('invoice_number');
        } else {
            $found = $query->whereIn('invoice_number', $numbers->all())->get()->keyBy('invoice_number');
        }
        $rows = [];
        $results = [];

        foreach ($numbers as $number) {
            /** @var Invoice|null $invoice */
            $invoice = $found->get($number);
            $eligibility = $this->eligibilityResult($invoice, $forceResend);
            $result = $eligibility;

            if ($eligibility === 'ready') {
                $result = $dryRun
                    ? 'would_send'
                    : $invoices->sendInitialInvoiceEmail($invoice, $forceResend);
            }

            $results[] = $result;
            $rows[] = [
                $number,
                $invoice?->customer?->full_name ?? '-',
                $invoice?->customer?->email ?? '-',
                $invoice ? number_format((float) $invoice->balance, 2) : '-',
                $invoice?->generation_source ?? '-',
                $result,
            ];
        }

        $this->table(
            ['Invoice', 'Customer', 'Recipient', 'Balance', 'Source', 'Result'],
            $rows,
        );

        $counts = collect($results)->countBy()->sortKeys();
        $this->line(($dryRun ? 'Preview' : 'Delivery') . ' summary: ' . $counts
            ->map(fn (int $count, string $result): string => "{$result}={$count}")
            ->implode(', '));
        $this->line('No invoice amount, date, status, payment, customer, or MikroTik record was changed.');

        return $counts->has('failed') || $counts->has('not_found')
            ? self::FAILURE
            : self::SUCCESS;
    }

    /** @return 'ready'|'not_found'|'skipped_already_sent'|'skipped_attempt_limit'|'skipped_no_email'|'skipped_no_balance' */
    public function eligibilityResult(?Invoice $invoice, bool $forceResend = false): string
    {
        if (!$invoice) {
            return 'not_found';
        }

        if (!$forceResend && $invoice->initial_email_sent_at !== null) {
            return 'skipped_already_sent';
        }

        if (!$forceResend && (int) $invoice->initial_email_attempt_count >= 2) {
            return 'skipped_attempt_limit';
        }

        if (!$invoice->customer || trim((string) $invoice->customer->email) === '') {
            return 'skipped_no_email';
        }

        if ((float) $invoice->balance <= 0) {
            return 'skipped_no_balance';
        }

        return 'ready';
    }
}
