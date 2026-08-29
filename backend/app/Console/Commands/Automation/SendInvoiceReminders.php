<?php

namespace App\Console\Commands\Automation;

use App\Models\AutomationLog;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\Automation\AutomationRunner;
use App\Services\BillingSmsReminderService;
use App\Services\CustomerWebPushNotificationService;
use App\Services\FinalGracePeriodWarningService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/** Sends billing reminders without changing invoices, payments, or suspension state. */
class SendInvoiceReminders extends Command
{
    protected $signature = 'automation:invoice-reminders
                            {--dry-run : Show what would be sent without sending}
                            {--triggered-by=schedule}
                            {--user-id=}';

    protected $description = 'Send Web Push reminders and queue one-time billing SMS/email events';

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

        $graceDays = max(0, (int) Setting::get('billing.auto_suspend_days', 15));

        $today = now(BillingSmsReminderService::TIMEZONE)->startOfDay();
        $details = [];
        $errors = [];
        $pushSent = 0;
        $smsQueued = 0;
        $finalWarningQueued = 0;
        $finalWarningPushSent = 0;
        $finalWarningDetails = [];
        $customersWithUnpaidInvoices = [];

        foreach (Invoice::unpaid()->with('customer')->get() as $invoice) {
            $customer = $invoice->customer;
            if (!$customer) {
                continue;
            }
            if ($customer->hasCompanyOwnedPlan()) {
                continue;
            }
            $customersWithUnpaidInvoices[$customer->id] = $customer;

            // Invoice dates are stored as calendar dates. Rebuild the due date
            // in Manila before calculating its difference; otherwise a UTC
            // cast produces values such as 7.333 days and skips the strict
            // one-time seven-day SMS condition below.
            $diffDays = $this->daysUntilDue($invoice, $today);
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

        // The final grace event is customer-level, because the existing
        // suspension service chooses the oldest invoice that truly triggers
        // restriction. Running this separately prevents duplicate final push
        // messages where a customer has more than one unpaid invoice.
        foreach ($customersWithUnpaidInvoices as $customer) {
            try {
                $finalWarning = app(FinalGracePeriodWarningService::class)->schedule($customer, $today, $dryRun);
                $event = $finalWarning['event'];
                if (!($event['eligible'] ?? false) || !$event['invoice']) {
                    continue;
                }

                $pushDelivery = $dryRun
                    ? (app(CustomerWebPushNotificationService::class)->statusFor($customer)['subscribed']
                        ? 'would_send'
                        : 'skipped_no_subscription')
                    : $this->sendReminder($customer, $event['invoice'], CustomerWebPushNotificationService::SUSPENSION_WARNING);

                $finalWarningPushSent += $pushDelivery === 'sent' ? 1 : 0;
                $finalWarningQueued += collect($finalWarning['deliveries'])->filter(fn (string $delivery) => $delivery === 'queued')->count();
                $finalWarningDetails[] = [
                    'invoice_number' => $event['invoice']->invoice_number,
                    'customer' => $customer->full_name,
                    'balance' => $event['outstanding_balance'],
                    'grace_period_end' => $event['grace_period_end']?->toDateString(),
                    'scheduled_suspension_at' => $event['suspension_at']?->toIso8601String(),
                    'sms_email' => $finalWarning['deliveries'],
                    'web_push_delivery' => $pushDelivery,
                ];
            } catch (\Throwable $e) {
                $errors[] = ['customer' => $customer->full_name, 'error' => $e->getMessage()];
            }
        }

        return [
            'dry_run' => $dryRun,
            'candidates' => Invoice::unpaid()->count(),
            'processed' => count($details),
            'push_sent' => $pushSent,
            'sms_queued' => $smsQueued,
            'final_warning_channels_queued' => $finalWarningQueued,
            'final_warning_push_sent' => $finalWarningPushSent,
            'email_policy' => 'initial invoice email plus one final grace-period warning email',
            'sms_policy' => 'one_time_7_days_before_due plus one final grace-period warning SMS',
            'final_grace_policy' => 'one queued SMS and one queued email on the final grace day; Web Push uses the existing suspension warning event',
            'errors' => $errors,
            'details' => $details,
            'final_grace_details' => $finalWarningDetails,
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
        // A zero-day grace period has its final warning on the due date, so
        // that customer-level workflow owns the Web Push event as well.
        if ($graceDays === 0 && $diffDays === 0) return null;
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
        // The customer-level final-grace workflow below owns this exact final
        // event. It reuses SUSPENSION_WARNING for one Web Push delivery but
        // also queues the separately audited SMS and email channels.
        if ($daysOverdue === $graceDays) return null;
        if ($daysOverdue === max(2, $graceDays - 7)) return CustomerWebPushNotificationService::GRACE_PERIOD_WARNING;
        if ($daysOverdue === 1) return CustomerWebPushNotificationService::BILLING_OVERDUE;

        return CustomerWebPushNotificationService::BILLING_DAILY_REMINDER;
    }

    /** Return the signed difference between two Manila calendar dates. */
    protected function daysUntilDue(Invoice $invoice, Carbon $today): int
    {
        $dueDate = Carbon::parse(
            $invoice->due_date->toDateString(),
            BillingSmsReminderService::TIMEZONE,
        )->startOfDay();

        return (int) $today->copy()
            ->setTimezone(BillingSmsReminderService::TIMEZONE)
            ->startOfDay()
            ->diffInDays($dueDate, false);
    }

}
