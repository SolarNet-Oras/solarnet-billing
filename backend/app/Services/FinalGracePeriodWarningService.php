<?php

namespace App\Services;

use App\Jobs\SendFinalGracePeriodWarning;
use App\Models\Customer;
use App\Models\FinalGracePeriodWarning;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Queues the final grace-period warning only. It does not suspend, restore,
 * create invoices, or alter billing dates; BillingSuspensionService remains
 * the authoritative source for all of those decisions.
 */
class FinalGracePeriodWarningService
{
    public const TIMEZONE = 'Asia/Manila';

    /**
     * Reserve one independently tracked SMS and email notification for the
     * current final-grace event, then hand each channel to the queue.
     *
     * @return array{status: string, event: array<string, mixed>, deliveries: array<string, string>}
     */
    public function schedule(Customer $customer, CarbonInterface $today, bool $dryRun = false): array
    {
        $event = $this->eventFor($customer, $today);
        if (!$event['eligible']) {
            return ['status' => 'skipped_not_due', 'event' => $event, 'deliveries' => []];
        }

        $deliveries = [];
        foreach ([FinalGracePeriodWarning::CHANNEL_SMS, FinalGracePeriodWarning::CHANNEL_EMAIL] as $channel) {
            $recipient = $this->recipientFor($customer, $channel);
            if ($dryRun) {
                $deliveries[$channel] = $this->isRecipientValid($recipient, $channel)
                    ? 'would_queue'
                    : 'would_skip_invalid_recipient';
                continue;
            }

            [$warning, $created] = $this->reserve($customer, $event, $channel, $recipient);
            if (!$created) {
                $deliveries[$channel] = 'skipped_duplicate';
                continue;
            }

            if ($event['portal_url'] === null) {
                $warning->forceFill([
                    'status' => 'skipped',
                    'failure_reason' => 'Configured customer portal URL is not a valid HTTPS URL.',
                ])->save();
                $deliveries[$channel] = 'skipped_invalid_portal_url';
                continue;
            }

            if (!$this->isRecipientValid($recipient, $channel)) {
                $warning->forceFill([
                    'status' => 'invalid',
                    'failure_reason' => $channel === FinalGracePeriodWarning::CHANNEL_SMS
                        ? 'Customer does not have a valid Philippine mobile number.'
                        : 'Customer does not have a valid email address.',
                ])->save();
                $deliveries[$channel] = 'skipped_invalid_recipient';
                continue;
            }

            if ($channel === FinalGracePeriodWarning::CHANNEL_SMS && !$this->smsEnabled()) {
                $warning->forceFill([
                    'status' => 'skipped',
                    'failure_reason' => 'Billing SMS reminders are disabled or PhilSMS is not configured.',
                ])->save();
                $deliveries[$channel] = 'skipped_sms_not_configured';
                continue;
            }

            SendFinalGracePeriodWarning::dispatch($warning->id);
            $deliveries[$channel] = 'queued';
        }

        return [
            'status' => in_array('queued', $deliveries, true) ? 'queued' : 'skipped',
            'event' => $event,
            'deliveries' => $deliveries,
        ];
    }

    /**
     * The scheduler and worker both call this method. That second worker-time
     * evaluation is what prevents a warning after a payment race is settled.
     *
     * @return array<string, mixed>
     */
    public function eventFor(Customer $customer, CarbonInterface $today): array
    {
        $customer->loadMissing('servicePlan');
        $today = Carbon::instance($today)->setTimezone(self::TIMEZONE)->startOfDay();
        $schedule = app(BillingSuspensionService::class)->gracePeriodSchedule($customer, $today);
        $invoice = $schedule['triggering_invoice'];
        $portalUrl = app(BillingSmsReminderService::class)->portalUrl();

        $eligibleCustomer = $customer->status === 'active' || $customer->suspension_source === 'automation';
        $suspensionEnabled = (bool) Setting::get('automation.auto_suspend_enabled', true);
        $isFinalWarningDay = $invoice !== null
            && $schedule['grace_period_end'] !== null
            && $today->isSameDay($schedule['grace_period_end']);

        return [
            'eligible' => $eligibleCustomer
                && $suspensionEnabled
                && !$schedule['company_owned']
                && (float) $schedule['outstanding_balance'] > 0
                && $isFinalWarningDay,
            'is_final_warning_day' => $isFinalWarningDay,
            'invoice' => $invoice,
            'outstanding_balance' => (float) $schedule['outstanding_balance'],
            'original_due_date' => $schedule['oldest_due_date'],
            'grace_days' => $schedule['grace_days'],
            'grace_period_start' => $schedule['grace_period_start'],
            'grace_period_end' => $schedule['grace_period_end'],
            'suspension_at' => $schedule['suspension_at'],
            'portal_url' => $portalUrl,
            'suspension_enabled' => $suspensionEnabled,
        ];
    }

    /** @return 'sent'|'skipped'|'retry'|'failed'|'invalid' */
    public function deliver(string $warningId): string
    {
        $warning = FinalGracePeriodWarning::with(['customer.servicePlan', 'invoice'])->find($warningId);
        if (!$warning || in_array($warning->status, ['sent', 'skipped', 'invalid', 'failed'], true)) {
            return 'skipped';
        }

        $event = $warning->customer ? $this->eventFor($warning->customer, now(self::TIMEZONE)) : null;
        if (!$this->matchesCurrentEvent($warning, $event)) {
            $this->markSkipped($warning, 'Billing balance, invoice, or grace-period eligibility changed before delivery.');
            return 'skipped';
        }

        // Claim this channel atomically. Repeated schedules, queue retries,
        // and deployment restarts cannot make two workers deliver the same row.
        $claimed = FinalGracePeriodWarning::query()
            ->whereKey($warning->id)
            ->whereIn('status', ['queued', 'retrying'])
            ->update([
                'status' => 'sending',
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_attempt_at' => now(),
                'failure_reason' => null,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return 'skipped';
        }

        $warning->refresh();
        $warning->load(['customer.servicePlan', 'invoice']);
        $event = $warning->customer ? $this->eventFor($warning->customer, now(self::TIMEZONE)) : null;
        if (!$this->matchesCurrentEvent($warning, $event)) {
            $this->markSkipped($warning, 'Billing balance, invoice, or grace-period eligibility changed immediately before delivery.');
            return 'skipped';
        }

        $warning->forceFill([
            'recipient' => $this->recipientFor($warning->customer, $warning->channel),
            'amount' => $event['outstanding_balance'],
            'original_due_date' => $event['original_due_date']->toDateString(),
            'grace_period_start' => $event['grace_period_start']->toDateString(),
            'grace_period_end' => $event['grace_period_end']->toDateString(),
            'suspension_at' => $event['suspension_at'],
            'portal_url' => $event['portal_url'],
        ])->save();

        return $warning->channel === FinalGracePeriodWarning::CHANNEL_SMS
            ? $this->deliverSms($warning, $event)
            : $this->deliverEmail($warning, $event);
    }

    public function smsMessage(Customer $customer, array $event): string
    {
        return 'SOLARNET: FINAL WARNING. Your account has an outstanding balance of PHP '
            . number_format((float) $event['outstanding_balance'], 2)
            . '. Your ' . $event['grace_days'] . '-day grace period ends today. Please settle now to avoid service suspension. Pay: '
            . $event['portal_url'];
    }

    public function emailSubject(): string
    {
        return 'SolarNet Final Billing Warning - Service Suspension Pending';
    }

    public function emailBody(Customer $customer, array $event): string
    {
        $company = (string) Setting::get('company.name', 'SolarNet Connection');

        return "Hello {$customer->full_name},\n\n"
            . "This is a final reminder regarding your SolarNet Internet account.\n\n"
            . 'Outstanding balance: PHP ' . number_format((float) $event['outstanding_balance'], 2) . "\n"
            . 'Original due date: ' . $event['original_due_date']->format('F j, Y') . "\n"
            . 'Grace period: ' . $event['grace_days'] . " days\n"
            . 'Your grace period ends: ' . $event['grace_period_end']->format('F j, Y') . "\n\n"
            . "Your service is scheduled for suspension according to our billing policy if the outstanding balance remains unpaid.\n\n"
            . "Please settle your account before the grace period expires.\n\n"
            . "Pay securely through your SolarNet Customer Portal:\n{$event['portal_url']}\n\n"
            . "If you have already made a payment, please allow the payment to be verified and posted to your account.\n\n"
            . "Thank you,\n{$company}\nBilling Department\n";
    }

    /** @return array{0: FinalGracePeriodWarning, 1: bool} */
    private function reserve(Customer $customer, array $event, string $channel, ?string $recipient): array
    {
        $attributes = [
            'customer_id' => $customer->id,
            'invoice_id' => $event['invoice']->id,
            'notification_type' => FinalGracePeriodWarning::TYPE,
            'channel' => $channel,
        ];
        $values = [
            'recipient' => $recipient,
            'amount' => $event['outstanding_balance'],
            'original_due_date' => $event['original_due_date']->toDateString(),
            'grace_period_start' => $event['grace_period_start']->toDateString(),
            'grace_period_end' => $event['grace_period_end']->toDateString(),
            'suspension_at' => $event['suspension_at'],
            'portal_url' => $event['portal_url'] ?? '',
            'status' => 'queued',
        ];

        try {
            $warning = FinalGracePeriodWarning::query()->firstOrCreate($attributes, $values);
        } catch (QueryException) {
            $warning = FinalGracePeriodWarning::query()->where($attributes)->firstOrFail();
        }

        return [$warning, $warning->wasRecentlyCreated];
    }

    private function matchesCurrentEvent(FinalGracePeriodWarning $warning, ?array $event): bool
    {
        return $event !== null
            && ($event['eligible'] ?? false)
            && $event['portal_url'] !== null
            && ($event['invoice']?->id === $warning->invoice_id)
            && (float) $event['outstanding_balance'] > 0;
    }

    private function deliverSms(FinalGracePeriodWarning $warning, array $event): string
    {
        if (!$this->smsEnabled() || !$this->isRecipientValid($warning->recipient, FinalGracePeriodWarning::CHANNEL_SMS)) {
            $warning->forceFill(['status' => 'invalid', 'failure_reason' => 'SMS channel is unavailable or the customer mobile number is invalid.'])->save();
            return 'invalid';
        }

        $service = app(PhilSmsService::class);
        $delivery = $service->send($warning->recipient, $this->smsMessage($warning->customer, $event));
        if ($delivery === 'sent') {
            $warning->forceFill([
                'status' => 'sent',
                'provider_message_id' => $service->lastProviderMessageId(),
                'sent_at' => now(),
                'failure_reason' => null,
            ])->save();
            return 'sent';
        }

        $reason = $service->lastFailureReason() ?? "PhilSMS delivery result: {$delivery}.";
        if (in_array($delivery, ['skipped_invalid_phone', 'skipped_no_phone'], true)) {
            $warning->forceFill(['status' => 'invalid', 'failure_reason' => $reason])->save();
            return 'invalid';
        }
        if ($this->isTemporarySmsFailure($reason)) {
            $warning->forceFill(['status' => 'retrying', 'failure_reason' => $reason])->save();
            return 'retry';
        }

        $warning->forceFill(['status' => 'failed', 'failure_reason' => $reason])->save();
        return 'failed';
    }

    private function deliverEmail(FinalGracePeriodWarning $warning, array $event): string
    {
        if (!$this->isRecipientValid($warning->recipient, FinalGracePeriodWarning::CHANNEL_EMAIL)) {
            $warning->forceFill(['status' => 'invalid', 'failure_reason' => 'Customer does not have a valid email address.'])->save();
            return 'invalid';
        }

        try {
            Mail::raw($this->emailBody($warning->customer, $event), function (Message $message) use ($warning) {
                $message->to($warning->recipient, $warning->customer->full_name)
                    ->subject($this->emailSubject());
            });
            $warning->forceFill(['status' => 'sent', 'sent_at' => now(), 'failure_reason' => null])->save();
            return 'sent';
        } catch (\Throwable $e) {
            $reason = substr($e->getMessage(), 0, 500);
            if ($this->isClearlyInvalidEmailFailure($reason)) {
                $warning->forceFill(['status' => 'invalid', 'failure_reason' => $reason])->save();
                return 'invalid';
            }

            $warning->forceFill(['status' => 'retrying', 'failure_reason' => $reason])->save();
            Log::warning('Final grace-period warning email failed; queue retry requested', [
                'warning_id' => $warning->id,
                'customer_id' => $warning->customer_id,
            ]);
            return 'retry';
        }
    }

    private function recipientFor(Customer $customer, string $channel): ?string
    {
        if ($channel === FinalGracePeriodWarning::CHANNEL_SMS) {
            return app(PhilSmsService::class)->normalisePhilippineMobile((string) $customer->contact_number);
        }

        $email = trim((string) $customer->email);
        return $email === '' ? null : $email;
    }

    private function isRecipientValid(?string $recipient, string $channel): bool
    {
        if ($recipient === null || $recipient === '') return false;

        return $channel === FinalGracePeriodWarning::CHANNEL_SMS
            ? app(PhilSmsService::class)->normalisePhilippineMobile($recipient) !== null
            : filter_var($recipient, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function smsEnabled(): bool
    {
        return (bool) Setting::get('billing.sms_reminder_enabled', true)
            && app(PhilSmsService::class)->isConfigured();
    }

    private function markSkipped(FinalGracePeriodWarning $warning, string $reason): void
    {
        $warning->forceFill(['status' => 'skipped', 'failure_reason' => $reason])->save();
    }

    private function isTemporarySmsFailure(string $reason): bool
    {
        return str_starts_with($reason, 'Network request to PhilSMS failed:')
            || preg_match('/PhilSMS returned HTTP (429|500|502|503|504)\\b/', $reason) === 1;
    }

    private function isClearlyInvalidEmailFailure(string $reason): bool
    {
        $reason = strtolower($reason);
        return str_contains($reason, 'invalid address') || str_contains($reason, 'must be a valid email');
    }
}
