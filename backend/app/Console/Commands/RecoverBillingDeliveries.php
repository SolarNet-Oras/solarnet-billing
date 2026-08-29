<?php

namespace App\Console\Commands;

use App\Jobs\SendBillingSmsReminder;
use App\Jobs\SendInitialInvoiceEmail;
use App\Models\BillingSmsNotification;
use App\Models\Invoice;
use App\Models\Setting;
use App\Services\BillingSmsReminderService;
use Illuminate\Console\Command;

class RecoverBillingDeliveries extends Command
{
    protected $signature = 'automation:recover-billing-deliveries {--limit=25 : Maximum records per channel}';

    protected $description = 'Requeue audited billing email/SMS deliveries that were not accepted and still have one retry available';

    public function handle(BillingSmsReminderService $smsReminders): int
    {
        if (!(bool) Setting::get('automation.enabled', true)) {
            $this->info('Billing delivery recovery is disabled with automation.');
            return self::SUCCESS;
        }

        $limit = min(100, max(1, (int) $this->option('limit')));
        $staleBefore = now()->subMinutes(10);

        // Deliberately require a non-null audit status. Legacy invoices are
        // never bulk emailed merely because this migration added new columns.
        $emailIds = Invoice::query()
            ->where('generation_source', 'recurring')
            ->whereNull('initial_email_sent_at')
            ->where('initial_email_attempt_count', '<', 2)
            ->whereIn('initial_email_status', ['queued', 'failed', 'skipped_no_email'])
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereHas('customer', fn ($query) => $query
                ->whereNotNull('email')
                ->where('email', '!=', ''))
            ->where(function ($query) use ($staleBefore) {
                $query->where(function ($queued) use ($staleBefore) {
                    $queued->where('initial_email_status', 'queued')
                        ->where('updated_at', '<=', $staleBefore);
                })->orWhere(function ($attempted) use ($staleBefore) {
                    $attempted->whereIn('initial_email_status', ['failed', 'skipped_no_email'])
                        ->where(function ($time) use ($staleBefore) {
                            $time->whereNull('initial_email_last_attempt_at')
                                ->orWhere('initial_email_last_attempt_at', '<=', $staleBefore);
                        });
                });
            })
            ->orderBy('due_date')
            ->limit($limit)
            ->pluck('id');

        foreach ($emailIds as $invoiceId) {
            Invoice::query()->whereKey($invoiceId)->update(['initial_email_status' => 'queued']);
            SendInitialInvoiceEmail::dispatch($invoiceId);
        }

        // Repair a missing SMS audit row while the invoice is still exactly
        // seven days before due. schedule() retains the database uniqueness
        // guard, so this cannot create a second reminder for the same invoice.
        $today = now(BillingSmsReminderService::TIMEZONE)->startOfDay();
        $missingSmsInvoices = Invoice::query()
            ->with('customer.servicePlan')
            ->where('generation_source', 'recurring')
            ->whereDate('due_date', $today->copy()->addDays(BillingSmsReminderService::DAYS_BEFORE_DUE))
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereDoesntHave('billingSmsNotifications', fn ($query) => $query
                ->where('notification_type', BillingSmsNotification::TYPE_7_DAYS))
            ->limit($limit)
            ->get();

        $smsCreated = 0;
        foreach ($missingSmsInvoices as $invoice) {
            if ($smsReminders->schedule($invoice, $today) === 'queued') {
                $smsCreated++;
            }
        }

        $smsIds = BillingSmsNotification::query()
            ->whereNull('sent_at')
            ->where('attempt_count', '<', 2)
            ->whereIn('status', ['queued', 'retrying', 'failed'])
            ->whereDate('due_date', now('Asia/Manila')->addDays(7)->toDateString())
            ->where(function ($query) use ($staleBefore) {
                $query->where(function ($queued) use ($staleBefore) {
                    $queued->where('status', 'queued')->where('updated_at', '<=', $staleBefore);
                })->orWhere('last_attempt_at', '<=', $staleBefore);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($smsIds as $notificationId) {
            BillingSmsNotification::query()->whereKey($notificationId)->update(['status' => 'queued']);
            SendBillingSmsReminder::dispatch($notificationId);
        }

        $this->info("Requeued email={$emailIds->count()}, sms={$smsIds->count()}; created missing SMS audit={$smsCreated}.");
        return self::SUCCESS;
    }
}
