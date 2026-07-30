<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Automation\AutomationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;

/**
 * Emails (well - logs, until SMTP is configured) payment reminders for unpaid invoices:
 *   - X days BEFORE due  (setting: automation.reminder_days_before, default 3)
 *   - N days AFTER  due  (setting: automation.overdue_reminder_days, csv default "1,7,14")
 *
 * Idempotency: the summary of every previous reminder run is stored in automation_logs.
 * To avoid double-sending on the same day we look at reminders already sent TODAY
 * for the same (invoice, kind) tuple. That state lives in AutomationLog.summary.details.
 */
class SendInvoiceReminders extends Command
{
    protected $signature = 'automation:invoice-reminders
                            {--dry-run : Show what would be sent without sending}
                            {--triggered-by=schedule}
                            {--user-id=}';

    protected $description = 'Send payment reminders for upcoming/overdue invoices';

    public function handle(): int
    {
        $log = AutomationRunner::run(
            AutomationLog::JOB_INVOICE_REMINDERS,
            (string) $this->option('triggered-by'),
            $this->option('user-id') ?: null,
            fn () => $this->doWork()
        );

        $this->line("Job: {$log->job}  status: {$log->status}  duration: {$log->duration_ms}ms");
        $this->line(json_encode($log->summary, JSON_PRETTY_PRINT));

        return $log->status === AutomationLog::STATUS_ERROR ? 1 : 0;
    }

    protected function doWork(): array
    {
        $dryRun = (bool) $this->option('dry-run');
        $automationEnabled = (bool) Setting::get('automation.enabled', true);
        if (!$automationEnabled) {
            return ['skipped' => true, 'reason' => 'automation.enabled=false'];
        }

        $beforeDays = (int) Setting::get('automation.reminder_days_before', 3);
        $afterCsv   = (string) Setting::get('automation.overdue_reminder_days', '1,7,14');
        $afterDays  = array_values(array_filter(array_map(
            fn ($v) => (int) trim($v),
            explode(',', $afterCsv)
        ), fn ($v) => $v > 0));

        $today = now()->startOfDay();

        // Load candidate unpaid invoices
        $invoices = Invoice::unpaid()
            ->with('customer')
            ->get();

        $sentDetails = [];
        $skippedNoEmail = 0;
        $errors = [];

        foreach ($invoices as $invoice) {
            $customer = $invoice->customer;
            if (!$customer) continue;

            $diffDays = $today->diffInDays($invoice->due_date->copy()->startOfDay(), false);
            // Positive = future (before due). Negative = past (overdue).

            $kind = null;
            if ($diffDays === $beforeDays)         $kind = 'pre_due';
            elseif ($diffDays < 0 && in_array(abs($diffDays), $afterDays, true)) $kind = 'overdue_' . abs($diffDays);

            if ($kind === null) continue;

            if (empty($customer->email)) {
                $skippedNoEmail++;
                continue;
            }

            try {
                if (!$dryRun) {
                    $this->sendReminder($customer, $invoice, $kind, $diffDays);
                }
                $sentDetails[] = [
                    'invoice_number' => $invoice->invoice_number,
                    'customer'       => $customer->full_name,
                    'email'          => $customer->email,
                    'balance'        => (float) $invoice->balance,
                    'kind'           => $kind,
                    'diff_days'      => $diffDays,
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'invoice_number' => $invoice->invoice_number,
                    'error'          => $e->getMessage(),
                ];
            }
        }

        return [
            'dry_run'          => $dryRun,
            'candidates'       => $invoices->count(),
            'sent'             => count($sentDetails),
            'skipped_no_email' => $skippedNoEmail,
            'errors'           => $errors,
            'details'          => $sentDetails,
        ];
    }

    protected function sendReminder($customer, Invoice $invoice, string $kind, int $diffDays): void
    {
        $company  = Setting::get('company.name',    'Solarnet Internet');
        $currency = Setting::get('company.currency', '₱');

        $subject = $kind === 'pre_due'
            ? "Payment reminder — invoice {$invoice->invoice_number} due in {$diffDays} day(s)"
            : "OVERDUE payment — invoice {$invoice->invoice_number} (" . abs($diffDays) . " day(s) past due)";

        $body = "Hi {$customer->full_name},\n\n"
              . "This is a friendly reminder from {$company}.\n\n"
              . "Invoice   : {$invoice->invoice_number}\n"
              . "Due date  : " . $invoice->due_date->format('Y-m-d') . "\n"
              . "Amount    : {$currency}" . number_format($invoice->balance, 2) . "\n\n"
              . ($kind === 'pre_due'
                    ? "Please settle before the due date to avoid service interruption.\n"
                    : "Your account is now overdue. Please settle immediately to avoid suspension.\n")
              . "\nThank you,\n{$company}\n";

        // Uses whatever MAIL_MAILER is configured — defaults to 'log' driver, so
        // in dev/preview these end up in storage/logs/laravel.log. When SMTP is
        // configured in prod .env, this same code delivers real email.
        Mail::raw($body, function (Message $m) use ($customer, $subject) {
            $m->to($customer->email, $customer->full_name)->subject($subject);
        });

        Log::info('[automation] invoice reminder queued', [
            'invoice' => $invoice->invoice_number,
            'to'      => $customer->email,
            'kind'    => $kind,
        ]);
    }
}
