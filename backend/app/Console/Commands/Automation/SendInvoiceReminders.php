<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Automation\AutomationRunner;
use App\Services\CustomerWebPushNotificationService;
use App\Services\InvoiceService;
use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Sends invoice-PDF emails and optional SMS reminders for unpaid invoices. */
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
        if (!(bool) Setting::get('automation.enabled', true)) {
            return ['skipped' => true, 'reason' => 'automation.enabled=false'];
        }

        $beforeDays = (int) Setting::get('automation.reminder_days_before', 3);
        $afterDays = array_values(array_filter(array_map(
            fn ($value) => (int) trim($value),
            explode(',', (string) Setting::get('automation.overdue_reminder_days', '1,7,14'))
        ), fn ($value) => $value > 0));
        $graceDays = max(1, (int) Setting::get('billing.auto_suspend_days', 15));

        $today = now()->startOfDay();
        $details = [];
        $errors = [];
        $emailSent = 0;
        $smsSent = 0;
        $pushSent = 0;
        $skippedNoEmail = 0;
        $skippedNoPhone = 0;
        $skippedSmsNotConfigured = 0;

        foreach (Invoice::unpaid()->with('customer')->get() as $invoice) {
            $customer = $invoice->customer;
            if (!$customer) {
                continue;
            }
            if ($customer->hasCompanyOwnedPlan()) {
                continue;
            }

            $diffDays = $today->diffInDays($invoice->due_date->copy()->startOfDay(), false);
            $kind = $diffDays === $beforeDays
                ? 'pre_due'
                : ($diffDays < 0 && in_array(abs($diffDays), $afterDays, true) ? 'overdue_' . abs($diffDays) : null);
            $pushType = $this->pushTypeFor($diffDays, $graceDays);
            if ($kind === null && $pushType === null) {
                continue;
            }

            try {
                $delivery = $dryRun
                    ? [
                        'email' => $kind === null ? 'skipped_not_scheduled' : (empty($customer->email) ? 'skipped_no_email' : 'would_send'),
                        'sms' => $kind === null ? 'skipped_not_scheduled' : (empty($customer->contact_number)
                            ? 'skipped_no_phone'
                            : ($this->twilioIsConfigured() ? 'would_send' : 'skipped_not_configured')),
                        'push' => $pushType === null ? 'skipped_not_scheduled' : (app(CustomerWebPushNotificationService::class)->statusFor($customer)['subscribed']
                            ? 'would_send'
                            : 'skipped_no_subscription'),
                    ]
                    : $this->sendReminder($customer, $invoice, $kind, $diffDays, $pushType);

                $emailSent += $delivery['email'] === 'sent' ? 1 : 0;
                $smsSent += $delivery['sms'] === 'sent' ? 1 : 0;
                $pushSent += $delivery['push'] === 'sent' ? 1 : 0;
                $skippedNoEmail += str_contains($delivery['email'], 'no_email') ? 1 : 0;
                $skippedNoPhone += str_contains($delivery['sms'], 'no_phone') ? 1 : 0;
                $skippedSmsNotConfigured += $delivery['sms'] === 'skipped_not_configured' ? 1 : 0;
                $details[] = [
                    'invoice_number' => $invoice->invoice_number,
                    'customer' => $customer->full_name,
                    'email' => $customer->email,
                    'phone' => $customer->contact_number,
                    'balance' => (float) $invoice->balance,
                    'kind' => $kind,
                    'push_type' => $pushType,
                    'diff_days' => $diffDays,
                    'email_delivery' => $delivery['email'],
                    'sms_delivery' => $delivery['sms'],
                    'push_delivery' => $delivery['push'],
                ];
            } catch (\Throwable $e) {
                $errors[] = ['invoice_number' => $invoice->invoice_number, 'error' => $e->getMessage()];
            }
        }

        return [
            'dry_run' => $dryRun,
            'candidates' => Invoice::unpaid()->count(),
            'processed' => count($details),
            'email_sent' => $emailSent,
            'sms_sent' => $smsSent,
            'push_sent' => $pushSent,
            'skipped_no_email' => $skippedNoEmail,
            'skipped_no_phone' => $skippedNoPhone,
            'skipped_sms_not_configured' => $skippedSmsNotConfigured,
            'errors' => $errors,
            'details' => $details,
        ];
    }

    /** @return array{email: string, sms: string, push: string} */
    protected function sendReminder($customer, Invoice $invoice, ?string $kind, int $diffDays, ?string $pushType): array
    {
        $emailDelivery = 'skipped_not_scheduled';
        $smsDelivery = 'skipped_not_scheduled';

        // Preserve the existing email/SMS schedule. Push may additionally
        // send the requested 7/3/1-day, due-day, and grace-period events.
        if ($kind !== null) {
        $company = Setting::get('company.name', 'Solarnet Internet');
        $currency = Setting::get('company.currency', 'PHP ');
        $subject = $kind === 'pre_due'
            ? "Payment reminder - invoice {$invoice->invoice_number} due in {$diffDays} day(s)"
            : "OVERDUE payment - invoice {$invoice->invoice_number} (" . abs($diffDays) . ' day(s) past due)';
        $body = "Hi {$customer->full_name},\n\n"
            . "This is a friendly reminder from {$company}.\n\n"
            . "Invoice   : {$invoice->invoice_number}\n"
            . 'Due date  : ' . $invoice->due_date->format('Y-m-d') . "\n"
            . "Amount    : {$currency}" . number_format($invoice->balance, 2) . "\n\n"
            . ($kind === 'pre_due'
                ? "Please settle before the due date to avoid service interruption.\n"
                : "Your account is now overdue. Please settle immediately to avoid suspension.\n")
            . "\nThank you,\n{$company}\n";

        $emailDelivery = 'skipped_no_email';
        if (!empty($customer->email)) {
            try {
                // Generate the bill in memory so it is never exposed as a public file.
                $pdf = app(InvoiceService::class)->generatePdf($invoice)->output();
                Mail::raw($body, function (Message $message) use ($customer, $subject, $pdf, $invoice) {
                    $message->to($customer->email, $customer->full_name)
                        ->subject($subject)
                        ->attachData($pdf, "invoice-{$invoice->invoice_number}.pdf", ['mime' => 'application/pdf']);
                });
                $emailDelivery = 'sent';
            } catch (\Throwable $e) {
                $emailDelivery = 'failed';
                Log::warning('[automation] invoice reminder email failed', [
                    'invoice' => $invoice->invoice_number,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Keep SMS concise: it is a billing alert, while the complete bill is
        // attached to the email above.
        $smsBody = "{$company}: invoice {$invoice->invoice_number}, balance {$currency}"
            . number_format($invoice->balance, 2)
            . ', due ' . $invoice->due_date->format('Y-m-d')
            . ($kind === 'pre_due' ? '. Please pay before the due date.' : '. Your account is overdue; please pay to avoid suspension.');
        $smsDelivery = $this->sendSmsReminder($customer->contact_number, $smsBody);
        }

        $pushDelivery = $pushType === null
            ? 'skipped_not_scheduled'
            : app(CustomerWebPushNotificationService::class)->sendBillingEvent($customer, $invoice, $pushType);
        Log::info('[automation] invoice reminder processed', [
            'invoice' => $invoice->invoice_number,
            'to' => $customer->email,
            'phone' => $customer->contact_number,
            'kind' => $kind,
            'push_type' => $pushType,
            'email_delivery' => $emailDelivery,
            'sms_delivery' => $smsDelivery,
            'push_delivery' => $pushDelivery,
        ]);

        return ['email' => $emailDelivery, 'sms' => $smsDelivery, 'push' => $pushDelivery];
    }

    /**
     * Push timing is intentionally additive to the existing email/SMS settings.
     * It never changes invoice generation, due dates, grace periods, or suspension.
     */
    protected function pushTypeFor(int $diffDays, int $graceDays): ?string
    {
        if ($diffDays === 7) return CustomerWebPushNotificationService::BILLING_REMINDER_7_DAYS;
        if ($diffDays === 3) return CustomerWebPushNotificationService::BILLING_REMINDER_3_DAYS;
        if ($diffDays === 1) return CustomerWebPushNotificationService::BILLING_REMINDER_1_DAY;
        if ($diffDays === 0) return CustomerWebPushNotificationService::BILLING_DUE_TODAY;

        $daysOverdue = abs($diffDays);
        if ($diffDays >= 0) return null;
        if ($daysOverdue === max(1, $graceDays - 1)) return CustomerWebPushNotificationService::SUSPENSION_WARNING;
        if ($daysOverdue === max(2, $graceDays - 7)) return CustomerWebPushNotificationService::GRACE_PERIOD_WARNING;
        if ($daysOverdue === 1) return CustomerWebPushNotificationService::BILLING_OVERDUE;

        return null;
    }

    protected function sendSmsReminder(?string $phone, string $body): string
    {
        if (empty($phone)) {
            return 'skipped_no_phone';
        }
        if (config('services.sms.driver') !== 'twilio') {
            return 'skipped_not_configured';
        }

        if (!$this->twilioIsConfigured()) {
            Log::warning('[automation] SMS skipped: Twilio is not fully configured');
            return 'skipped_not_configured';
        }

        $sid = config('services.sms.twilio_sid');
        $token = config('services.sms.twilio_token');
        $from = config('services.sms.twilio_from');

        $to = $this->normalisePhone($phone);
        if ($to === null) {
            Log::warning('[automation] SMS skipped: invalid customer phone number', ['phone' => $phone]);
            return 'skipped_invalid_phone';
        }

        try {
            Http::asForm()
                ->withBasicAuth($sid, $token)
                ->timeout(15)
                ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                    'From' => $from,
                    'To' => $to,
                    'Body' => $body,
                ])
                ->throw();

            return 'sent';
        } catch (\Throwable $e) {
            Log::warning('[automation] invoice reminder SMS failed', ['phone' => $to, 'error' => $e->getMessage()]);
            return 'failed';
        }
    }

    protected function normalisePhone(string $phone): ?string
    {
        $trimmed = trim($phone);
        $digits = preg_replace('/\D+/', '', $trimmed);
        if (!$digits || strlen($digits) < 7) {
            return null;
        }
        if (str_starts_with($trimmed, '+')) {
            return '+' . $digits;
        }

        $countryCode = '+' . ltrim((string) config('services.sms.default_country_code', '+63'), '+');
        return str_starts_with($digits, '0') ? $countryCode . substr($digits, 1) : $countryCode . $digits;
    }

    protected function twilioIsConfigured(): bool
    {
        return config('services.sms.driver') === 'twilio'
            && filled(config('services.sms.twilio_sid'))
            && filled(config('services.sms.twilio_token'))
            && filled(config('services.sms.twilio_from'));
    }
}
