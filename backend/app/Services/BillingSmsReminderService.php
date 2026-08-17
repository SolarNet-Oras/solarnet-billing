<?php

namespace App\Services;

use App\Jobs\SendBillingSmsReminder;
use App\Models\BillingSmsNotification;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;

/**
 * Reserves and delivers the one permitted pre-due billing SMS: exactly seven
 * Manila calendar days before an unpaid invoice is due. The separately
 * audited final grace-period SMS is handled by FinalGracePeriodWarningService.
 */
class BillingSmsReminderService
{
    public const DAYS_BEFORE_DUE = 7;
    public const TIMEZONE = 'Asia/Manila';

    /** @return 'would_queue'|'queued'|'skipped_disabled'|'skipped_not_due'|'skipped_invalid'|'skipped_duplicate' */
    public function schedule(Invoice $invoice, CarbonInterface $today, bool $dryRun = false): string
    {
        $invoice->loadMissing('customer.servicePlan');
        $customer = $invoice->customer;

        if (!$this->isEnabled()) {
            return 'skipped_disabled';
        }

        if (!$customer || !$this->isEligibleInvoice($invoice, $today)) {
            return 'skipped_not_due';
        }

        $phone = app(PhilSmsService::class)->normalisePhilippineMobile((string) $customer->contact_number);
        $portalUrl = $this->portalUrl();
        $outstanding = $this->customerOutstanding($customer);
        if ($phone === null || $portalUrl === null || $outstanding <= 0) {
            return 'skipped_invalid';
        }

        if ($dryRun) {
            return 'would_queue';
        }

        [$notification, $created] = $this->reserve($invoice, $customer, $phone, $outstanding, $portalUrl);
        if (!$created) {
            return 'skipped_duplicate';
        }

        SendBillingSmsReminder::dispatch($notification->id);

        return 'queued';
    }

    /** @return 'sent'|'skipped'|'retry'|'failed'|'invalid' */
    public function deliver(string $notificationId): string
    {
        $notification = BillingSmsNotification::with(['customer.servicePlan', 'invoice'])->find($notificationId);
        if (!$notification || $notification->status === 'sent') {
            return 'skipped';
        }

        $invoice = $notification->invoice;
        $customer = $notification->customer;
        $today = now(self::TIMEZONE)->startOfDay();
        if (!$invoice || !$customer || !$this->isEnabled() || !$this->isEligibleInvoice($invoice, $today)) {
            $notification->forceFill([
                'status' => 'skipped',
                'failure_reason' => 'Invoice is no longer eligible for its one-time 7-day billing SMS.',
            ])->save();

            return 'skipped';
        }

        $phone = app(PhilSmsService::class)->normalisePhilippineMobile((string) $customer->contact_number);
        $portalUrl = $this->portalUrl();
        $outstanding = $this->customerOutstanding($customer);
        if ($phone === null) {
            $notification->forceFill(['status' => 'invalid', 'failure_reason' => 'Customer does not have a valid Philippine mobile number.'])->save();

            return 'invalid';
        }
        if ($portalUrl === null || $outstanding <= 0) {
            $notification->forceFill([
                'status' => 'skipped',
                'failure_reason' => $portalUrl === null ? 'Configured customer portal URL is not a valid HTTPS URL.' : 'Customer has no outstanding balance.',
            ])->save();

            return 'skipped';
        }

        $notification->forceFill([
            'phone_number' => $phone,
            'amount' => $outstanding,
            'due_date' => $this->dueDate($invoice)->toDateString(),
            'portal_url' => $portalUrl,
            'status' => 'sending',
            'attempt_count' => $notification->attempt_count + 1,
            'last_attempt_at' => now(),
            'failure_reason' => null,
        ])->save();

        $service = app(PhilSmsService::class);
        $delivery = $service->send($phone, $this->message($customer, $outstanding, $this->dueDate($invoice), $portalUrl));

        if ($delivery === 'sent') {
            $notification->forceFill([
                'status' => 'sent',
                'provider_message_id' => $service->lastProviderMessageId(),
                'sent_at' => now(),
            ])->save();

            return 'sent';
        }

        $reason = $service->lastFailureReason() ?? "PhilSMS delivery result: {$delivery}.";
        if (in_array($delivery, ['skipped_invalid_phone', 'skipped_no_phone'], true)) {
            $notification->forceFill(['status' => 'invalid', 'failure_reason' => $reason])->save();

            return 'invalid';
        }

        if ($this->isTemporaryFailure($reason)) {
            $notification->forceFill(['status' => 'retrying', 'failure_reason' => $reason])->save();

            return 'retry';
        }

        $notification->forceFill(['status' => 'failed', 'failure_reason' => $reason])->save();

        return 'failed';
    }

    public function isEligibleInvoice(Invoice $invoice, CarbonInterface $today): bool
    {
        $customer = $invoice->customer;
        if (!$customer || !in_array($customer->status, ['active', 'suspended', 'expired'], true) || $customer->hasCompanyOwnedPlan()) {
            return false;
        }

        return in_array($invoice->status, ['sent', 'partial', 'overdue'], true)
            && (float) $invoice->balance > 0
            && $this->isExactReminderDate($invoice, $today);
    }

    public function isExactReminderDate(Invoice $invoice, CarbonInterface $today): bool
    {
        return $today->copy()->setTimezone(self::TIMEZONE)->startOfDay()
            ->isSameDay($this->dueDate($invoice)->subDays(self::DAYS_BEFORE_DUE));
    }

    public function message(Customer $customer, float $outstanding, CarbonInterface $dueDate, string $portalUrl): string
    {
        $firstName = trim((string) preg_split('/\s+/', trim($customer->full_name))[0]);
        $firstName = $firstName !== '' ? $firstName : 'Customer';

        return 'SOLARNET: Hi ' . $firstName
            . ', your bill of PHP ' . number_format($outstanding, 2)
            . ' is due ' . $dueDate->copy()->setTimezone(self::TIMEZONE)->format('M j, Y')
            . '. Pay: ' . $portalUrl;
    }

    public function portalUrl(): ?string
    {
        $base = rtrim((string) config('app.url'), '/');
        $parts = parse_url($base);
        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null)) {
            return null;
        }

        return $base . '/customer/billing';
    }

    private function isEnabled(): bool
    {
        return (bool) Setting::get('billing.sms_reminder_enabled', true);
    }

    private function customerOutstanding(Customer $customer): float
    {
        return round((float) Invoice::unpaid()->where('customer_id', $customer->id)->sum('balance'), 2);
    }

    private function dueDate(Invoice $invoice): Carbon
    {
        return Carbon::parse($invoice->due_date->toDateString(), self::TIMEZONE)->startOfDay();
    }

    /** @return array{0: BillingSmsNotification, 1: bool} */
    private function reserve(Invoice $invoice, Customer $customer, string $phone, float $outstanding, string $portalUrl): array
    {
        $attributes = [
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'notification_type' => BillingSmsNotification::TYPE_7_DAYS,
        ];
        $values = [
            'phone_number' => $phone,
            'amount' => $outstanding,
            'due_date' => $this->dueDate($invoice)->toDateString(),
            'portal_url' => $portalUrl,
            'status' => 'queued',
        ];

        try {
            $notification = BillingSmsNotification::query()->firstOrCreate($attributes, $values);
        } catch (QueryException) {
            $notification = BillingSmsNotification::query()->where($attributes)->firstOrFail();
        }

        return [$notification, $notification->wasRecentlyCreated];
    }

    private function isTemporaryFailure(string $reason): bool
    {
        return str_starts_with($reason, 'Network request to PhilSMS failed:')
            || preg_match('/PhilSMS returned HTTP (429|500|502|503|504)\b/', $reason) === 1;
    }
}
