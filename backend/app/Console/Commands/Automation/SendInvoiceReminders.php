<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Automation\AutomationRunner;
use App\Services\BillingSmsReminderService;
use App\Services\CustomerWebPushNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Sends daily web-push reminders for unpaid invoices. */
class SendInvoiceReminders extends Command
{
    protected $signature = 'automation:invoice-reminders
                            {--dry-run : Show what would be sent without sending}
                            {--triggered-by=schedule}
                            {--user-id=}';

    protected $description = 'Send daily web-push payment reminders for upcoming/overdue invoices';

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

        $graceDays = max(1, (int) Setting::get('billing.auto_suspend_days', 15));

        $today = now(BillingSmsReminderService::TIMEZONE)->startOfDay();
        $details = [];
        $errors = [];
        $pushSent = 0;
        $smsQueued = 0;

        foreach (Invoice::unpaid()->with('customer')->get() as $invoice) {
            $customer = $invoice->customer;
            if (!$customer) {
                continue;
            }
            if ($customer->hasCompanyOwnedPlan()) {
                continue;
            }

            $diffDays = $today->diffInDays($invoice->due_date->copy()->startOfDay(), false);
            $pushType = $this->pushTypeFor($diffDays, $graceDays);
            if ($pushType === null) {
                continue;
            }

            try {
                $delivery = $dryRun
                    ? (app(CustomerWebPushNotificationService::class)->statusFor($customer)['subscribed']
                        ? 'would_send'
                        : 'skipped_no_subscription')
                    : $this->sendReminder($customer, $invoice, $pushType);

                $pushSent += $delivery === 'sent' ? 1 : 0;
                $smsDelivery = null;
                if ($diffDays === BillingSmsReminderService::DAYS_BEFORE_DUE) {
                    $smsDelivery = app(BillingSmsReminderService::class)->schedule($invoice, $today, $dryRun);
                    $smsQueued += $smsDelivery === 'queued' ? 1 : 0;
                }
                $details[] = [
                    'invoice_number' => $invoice->invoice_number,
                    'customer' => $customer->full_name,
                    'balance' => (float) $invoice->balance,
                    'push_type' => $pushType,
                    'diff_days' => $diffDays,
                    'push_delivery' => $delivery,
                    'sms_delivery' => $smsDelivery,
                ];
            } catch (\Throwable $e) {
                $errors[] = ['invoice_number' => $invoice->invoice_number, 'error' => $e->getMessage()];
            }
        }

        return [
            'dry_run' => $dryRun,
            'candidates' => Invoice::unpaid()->count(),
            'processed' => count($details),
            'push_sent' => $pushSent,
            'sms_queued' => $smsQueued,
            'email_policy' => 'initial_invoice_email_only',
            'sms_policy' => 'one_time_7_days_before_due',
            'errors' => $errors,
            'details' => $details,
        ];
    }

    protected function sendReminder($customer, Invoice $invoice, string $pushType): string
    {
        $delivery = app(CustomerWebPushNotificationService::class)->sendBillingEvent($customer, $invoice, $pushType);
        Log::info('[automation] daily invoice web-push reminder processed', [
            'invoice' => $invoice->invoice_number,
            'push_type' => $pushType,
            'push_delivery' => $delivery,
        ]);

        return $delivery;
    }

    /**
     * Starting seven days before due date, an unpaid invoice produces one
     * customer-device push per day until it is paid. It never changes invoice
     * generation, due dates, grace periods, or suspension.
     */
    protected function pushTypeFor(int $diffDays, int $graceDays): ?string
    {
        if ($diffDays === 7) return CustomerWebPushNotificationService::BILLING_REMINDER_7_DAYS;
        if ($diffDays === 3) return CustomerWebPushNotificationService::BILLING_REMINDER_3_DAYS;
        if ($diffDays === 1) return CustomerWebPushNotificationService::BILLING_REMINDER_1_DAY;
        if ($diffDays === 0) return CustomerWebPushNotificationService::BILLING_DUE_TODAY;

        // Fill the days between the established 7/3/1/due milestones. The
        // dispatch key includes today's date, so rerunning the scheduler does
        // not create duplicate notifications on the same day.
        if ($diffDays >= 0 && $diffDays <= 7) {
            return CustomerWebPushNotificationService::BILLING_DAILY_REMINDER;
        }

        $daysOverdue = abs($diffDays);
        if ($diffDays >= 0) return null;
        if ($daysOverdue === max(1, $graceDays - 1)) return CustomerWebPushNotificationService::SUSPENSION_WARNING;
        if ($daysOverdue === max(2, $graceDays - 7)) return CustomerWebPushNotificationService::GRACE_PERIOD_WARNING;
        if ($daysOverdue === 1) return CustomerWebPushNotificationService::BILLING_OVERDUE;

        return CustomerWebPushNotificationService::BILLING_DAILY_REMINDER;
    }

}
