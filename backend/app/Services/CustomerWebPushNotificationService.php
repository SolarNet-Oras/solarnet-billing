<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerNotificationLog;
use App\Models\CustomerWebPushSubscription;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Opt-in browser push for authenticated customer accounts.
 *
 * DHCP lease data is intentionally not used as push identity. A lease is a
 * network diagnostic; a stored browser subscription belongs to the customer
 * account that authenticated and explicitly granted notification permission.
 */
class CustomerWebPushNotificationService
{
    public const BILLING_REMINDER_7_DAYS = 'BILLING_REMINDER_7_DAYS';
    public const BILLING_REMINDER_3_DAYS = 'BILLING_REMINDER_3_DAYS';
    public const BILLING_REMINDER_1_DAY = 'BILLING_REMINDER_1_DAY';
    public const BILLING_DAILY_REMINDER = 'BILLING_DAILY_REMINDER';
    public const BILLING_DUE_TODAY = 'BILLING_DUE_TODAY';
    public const BILLING_OVERDUE = 'BILLING_OVERDUE';
    public const GRACE_PERIOD_WARNING = 'GRACE_PERIOD_WARNING';
    public const SUSPENSION_WARNING = 'SUSPENSION_WARNING';
    public const SERVICE_SUSPENDED = 'SERVICE_SUSPENDED';
    public const PAYMENT_RECEIVED = 'PAYMENT_RECEIVED';
    public const SERVICE_RESTORED = 'SERVICE_RESTORED';
    public const PUSH_TEST = 'PUSH_TEST';

    private const WEB_PUSH_CLASS = 'Minishlink\\WebPush\\WebPush';
    private const SUBSCRIPTION_CLASS = 'Minishlink\\WebPush\\Subscription';
    private const ALLOWED_ROUTES = [
        '/customer/dashboard',
        '/customer/billing',
    ];

    /** @return array{enabled: bool, subscribed: bool, subscription_count: int, reason: string|null} */
    public function statusFor(Customer $customer): array
    {
        $enabled = $this->isConfigured();
        $count = $customer->webPushSubscriptions()->active()->count();

        return [
            'enabled' => $enabled,
            'subscribed' => $count > 0,
            'subscription_count' => $count,
            'reason' => $enabled ? null : $this->configurationReason(),
        ];
    }

    public function isConfigured(): bool
    {
        return (bool) config('services.web_push.enabled', false)
            && filled(config('services.web_push.vapid_subject'))
            && filled(config('services.web_push.vapid_public_key'))
            && filled(config('services.web_push.vapid_private_key'))
            && class_exists(self::WEB_PUSH_CLASS)
            && class_exists(self::SUBSCRIPTION_CLASS);
    }

    public function sendBillingEvent(Customer $customer, Invoice $invoice, string $type): string
    {
        $daysUntilDue = now(config('app.timezone', 'Asia/Manila'))
            ->startOfDay()
            ->diffInDays($invoice->due_date->copy()->startOfDay(), false);

        $content = match ($type) {
            self::BILLING_REMINDER_7_DAYS => ['SolarNet billing reminder', 'Your SolarNet bill is due in 7 days. Open your account to review it.'],
            self::BILLING_REMINDER_3_DAYS => ['SolarNet billing reminder', 'Your SolarNet bill is due in 3 days. Open your account to review it.'],
            self::BILLING_REMINDER_1_DAY => ['SolarNet billing reminder', 'Your SolarNet bill is due tomorrow. Open your account to review it.'],
            self::BILLING_DAILY_REMINDER => $daysUntilDue >= 0
                ? ['SolarNet billing reminder', "Your SolarNet bill is due in {$daysUntilDue} day(s). Open your account to review it."]
                : ['SolarNet bill overdue', 'Your SolarNet account has an unpaid bill. Open your account to review payment options.'],
            self::BILLING_DUE_TODAY => ['SolarNet bill due today', 'Your SolarNet bill is due today. Open your account to review payment options.'],
            self::BILLING_OVERDUE => ['SolarNet bill overdue', 'Your SolarNet account has an overdue bill. Open your account to review it.'],
            self::GRACE_PERIOD_WARNING => ['SolarNet grace-period reminder', 'Your account has an unpaid bill and is inside its grace period. Open your account to review it.'],
            self::SUSPENSION_WARNING => $this->finalGraceWarningContent($customer),
            default => throw new \InvalidArgumentException("Unsupported billing notification type: {$type}"),
        };

        return $this->send($customer, $type, $content[0], $content[1], '/customer/billing', $invoice);
    }

    /** @param array{outstanding_balance?: float|int|string} $billingState */
    public function sendSuspensionNotice(Customer $customer, array $billingState = []): string
    {
        return $this->send(
            $customer,
            self::SERVICE_SUSPENDED,
            'SolarNet service suspended',
            'Your Internet service is temporarily suspended due to an unpaid balance. Open your account to review payment options.',
            '/customer/billing',
        );
    }

    public function sendServiceRestored(Customer $customer): string
    {
        return $this->send(
            $customer,
            self::SERVICE_RESTORED,
            'SolarNet service restored',
            'Your SolarNet Internet service has been restored. Open your account for the latest status.',
            '/customer/dashboard',
        );
    }

    public function sendPaymentReceived(Payment $payment): string
    {
        $payment->loadMissing('customer');
        if (!$payment->customer) {
            return 'skipped_customer_missing';
        }

        $invoice = $payment->invoice;
        $message = $invoice && (float) $invoice->balance <= 0
            ? 'Your payment is confirmed and the invoice is now PAID. Open your account to view the receipt.'
            : ($invoice
                ? 'Your payment was received as a partial payment. Open your account to view the remaining balance.'
                : 'Your advance payment was received and credited to your account. Open your account for details.');

        return $this->send(
            $payment->customer,
            self::PAYMENT_RECEIVED,
            $invoice && (float) $invoice->balance <= 0 ? 'SolarNet payment confirmed — PAID' : 'SolarNet payment received',
            $message,
            '/customer/billing',
            $payment->invoice,
            $payment,
        );
    }

    /** A server-side diagnostic; it never changes billing, router, or lease state. */
    public function sendTestNotification(Customer $customer): string
    {
        return $this->send(
            $customer,
            self::PUSH_TEST,
            'SolarNet notifications are working',
            'This is a test alert for your SolarNet customer portal account.',
            '/customer/dashboard',
            null,
            null,
            now()->format('Y-m-d-H-i-s-u'),
        );
    }

    /** Mark a notification as clicked only after authenticated account ownership is verified. */
    public function markClicked(Customer $customer, string $notificationId): bool
    {
        return CustomerNotificationLog::query()
            ->whereKey($notificationId)
            ->where('customer_id', $customer->id)
            ->whereNull('clicked_at')
            ->update(['clicked_at' => now(), 'status' => 'clicked']) > 0;
    }

    /**
     * @return 'sent'|'failed'|'skipped_not_configured'|'skipped_no_subscription'|'skipped_duplicate'
     */
    private function send(
        Customer $customer,
        string $type,
        string $title,
        string $body,
        string $route,
        ?Invoice $invoice = null,
        ?Payment $payment = null,
        ?string $eventSuffix = null,
    ): string {
        if (!$this->isConfigured()) {
            return 'skipped_not_configured';
        }
        if (!$this->isAllowedRoute($route)) {
            Log::error('Customer push refused an invalid internal route', ['customer_id' => $customer->id, 'type' => $type]);
            return 'failed';
        }

        $subscriptions = $customer->webPushSubscriptions()->active()->get();
        if ($subscriptions->isEmpty()) {
            return 'skipped_no_subscription';
        }

        $pending = [];
        foreach ($subscriptions as $subscription) {
            $dispatchKey = $this->dispatchKey($customer, $type, $invoice, $payment, $subscription, $eventSuffix);
            $log = CustomerNotificationLog::firstOrCreate(
                ['dispatch_key' => $dispatchKey],
                [
                    'customer_id' => $customer->id,
                    'invoice_id' => $invoice?->id,
                    'payment_id' => $payment?->id,
                    'subscription_id' => $subscription->id,
                    'notification_type' => $type,
                    'title' => $title,
                    'route' => $route,
                    'status' => 'queued',
                ],
            );

            if ($log->wasRecentlyCreated) {
                $pending[] = compact('subscription', 'log');
            }
        }

        if ($pending === []) {
            return 'skipped_duplicate';
        }

        try {
            $webPushClass = self::WEB_PUSH_CLASS;
            $subscriptionClass = self::SUBSCRIPTION_CLASS;
            $webPush = new $webPushClass([
                'VAPID' => [
                    'subject' => config('services.web_push.vapid_subject'),
                    'publicKey' => config('services.web_push.vapid_public_key'),
                    'privateKey' => config('services.web_push.vapid_private_key'),
                ],
            ], [
                'TTL' => $this->ttlFor($type),
                'urgency' => $this->urgencyFor($type),
                'contentType' => 'application/json',
            ]);

            $queued = [];
            foreach ($pending as $item) {
                /** @var CustomerWebPushSubscription $stored */
                $stored = $item['subscription'];
                /** @var CustomerNotificationLog $log */
                $log = $item['log'];
                try {
                    $pushSubscription = $subscriptionClass::create([
                        'endpoint' => $stored->endpoint,
                        'keys' => ['p256dh' => $stored->public_key, 'auth' => $stored->auth_token],
                        'contentEncoding' => $stored->content_encoding,
                    ]);
                    $payload = json_encode([
                        'title' => $title,
                        'body' => $body,
                        'url' => $this->routeForNotification($route, $log),
                        'tag' => 'solarnet-' . strtolower(str_replace('_', '-', $type)),
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $webPush->queueNotification($pushSubscription, $payload, ['topic' => substr($log->dispatch_key, 0, 32)]);
                    $queued[$stored->endpoint] = $item;
                } catch (Throwable) {
                    $this->markDeliveryFailed($log, $stored, 'subscription_invalid');
                }
            }

            if ($queued === []) {
                return 'failed';
            }

            $sent = 0;
            foreach ($webPush->flush() as $report) {
                $item = $queued[(string) $report->getEndpoint()] ?? null;
                if (!$item) {
                    continue;
                }
                /** @var CustomerWebPushSubscription $stored */
                $stored = $item['subscription'];
                /** @var CustomerNotificationLog $log */
                $log = $item['log'];

                if ($report->isSuccess()) {
                    $stored->forceFill([
                        'last_used_at' => now(),
                        'last_sent_at' => now(),
                        'failed_at' => null,
                        'failure_reason' => null,
                    ])->save();
                    $log->forceFill(['status' => 'sent', 'sent_at' => now(), 'failure_reason' => null])->save();
                    $sent++;
                    continue;
                }

                $this->markDeliveryFailed(
                    $log,
                    $stored,
                    $report->isSubscriptionExpired() ? 'subscription_expired' : 'push_service_rejected',
                    $report->isSubscriptionExpired(),
                );
            }

            Log::info('Customer web push event processed', [
                'customer_id' => $customer->id,
                'type' => $type,
                'subscriptions_queued' => count($queued),
                'sent' => $sent,
            ]);

            return $sent > 0 ? 'sent' : 'failed';
        } catch (Throwable $e) {
            foreach ($pending as $item) {
                $this->markDeliveryFailed($item['log'], $item['subscription'], 'delivery_error');
            }
            Log::warning('Customer web push delivery could not be completed', [
                'customer_id' => $customer->id,
                'type' => $type,
                'subscription_count' => count($pending),
                'error_type' => $e::class,
            ]);
            return 'failed';
        }
    }

    private function markDeliveryFailed(CustomerNotificationLog $log, CustomerWebPushSubscription $subscription, string $reason, bool $revoke = false): void
    {
        $log->forceFill(['status' => 'failed', 'failure_reason' => $reason])->save();
        $subscription->forceFill([
            'failed_at' => now(),
            'failure_reason' => $reason,
            'revoked_at' => $revoke ? now() : $subscription->revoked_at,
        ])->save();
    }

    /** @return array{0: string, 1: string} */
    private function finalGraceWarningContent(Customer $customer): array
    {
        $outstanding = (float) Invoice::unpaid()
            ->where('customer_id', $customer->id)
            ->sum('balance');

        return [
            'SolarNet final billing warning',
            'Your PHP ' . number_format($outstanding, 2) . ' outstanding balance reaches its final grace day today. Settle now to avoid service suspension.',
        ];
    }

    private function dispatchKey(Customer $customer, string $type, ?Invoice $invoice, ?Payment $payment, CustomerWebPushSubscription $subscription, ?string $eventSuffix): string
    {
        return hash('sha256', implode('|', [
            $customer->id,
            $invoice?->id ?? '-',
            $payment?->id ?? '-',
            $type,
            $eventSuffix ?? now(config('app.timezone', 'Asia/Manila'))->toDateString(),
            $subscription->id,
        ]));
    }

    private function routeForNotification(string $route, CustomerNotificationLog $log): string
    {
        return $route . '?notification=' . rawurlencode((string) $log->id);
    }

    private function isAllowedRoute(string $route): bool
    {
        return in_array($route, self::ALLOWED_ROUTES, true);
    }

    private function ttlFor(string $type): int
    {
        return in_array($type, [self::SERVICE_SUSPENDED, self::SUSPENSION_WARNING], true) ? 1209600 : 604800;
    }

    private function urgencyFor(string $type): string
    {
        return in_array($type, [self::SERVICE_SUSPENDED, self::SUSPENSION_WARNING, self::BILLING_DUE_TODAY], true) ? 'high' : 'normal';
    }

    private function configurationReason(): string
    {
        if (!class_exists(self::WEB_PUSH_CLASS) || !class_exists(self::SUBSCRIPTION_CLASS)) {
            return 'The Web Push server package has not been installed.';
        }

        return 'Web Push has not been configured on the server.';
    }
}
