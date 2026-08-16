<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerWebPushSubscription;
use App\Models\Invoice;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers opt-in browser notifications to the customer portal.
 *
 * A DHCP lease is deliberately not used as notification identity: an IP/MAC
 * address can change and does not prove that a phone belongs to an account.
 * The subscription is created only by an authenticated customer portal user.
 */
class CustomerWebPushNotificationService
{
    private const WEB_PUSH_CLASS = 'Minishlink\\WebPush\\WebPush';
    private const SUBSCRIPTION_CLASS = 'Minishlink\\WebPush\\Subscription';

    /** @return array{enabled: bool, subscribed: bool, reason: string|null} */
    public function statusFor(Customer $customer): array
    {
        $enabled = $this->isConfigured();

        return [
            'enabled' => $enabled,
            'subscribed' => $customer->webPushSubscriptions()->exists(),
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

    public function sendBillingReminder(Customer $customer, Invoice $invoice, string $kind): string
    {
        $isOverdue = str_starts_with($kind, 'overdue_');
        $currency = (string) config('services.web_push.currency_symbol', '₱');
        $amount = number_format((float) $invoice->balance, 2);

        return $this->send($customer, [
            'title' => $isOverdue ? 'SolarNet: account payment is overdue' : 'SolarNet: payment reminder',
            'body' => $isOverdue
                ? "Your balance of {$currency}{$amount} is overdue. Open your account to pay."
                : "Your {$currency}{$amount} payment is due on {$invoice->due_date?->format('M j, Y')}. Open your account to review it.",
            'url' => '/customer/dashboard',
            'tag' => $this->topic('invoice', (string) $invoice->id),
            'urgency' => $isOverdue ? 'high' : 'normal',
            'ttl' => $isOverdue ? 604800 : 172800,
        ]);
    }

    /** @param array{outstanding_balance?: float|int|string} $billingState */
    public function sendSuspensionNotice(Customer $customer, array $billingState = []): string
    {
        $currency = (string) config('services.web_push.currency_symbol', '₱');
        $balance = number_format((float) ($billingState['outstanding_balance'] ?? 0), 2);

        return $this->send($customer, [
            'title' => 'SolarNet: internet service suspended',
            'body' => "Your service is temporarily suspended. Balance: {$currency}{$balance}. Tap to open your account and pay securely.",
            'url' => '/customer/dashboard',
            'tag' => $this->topic('suspension', (string) $customer->id),
            'urgency' => 'high',
            'ttl' => 1209600,
        ]);
    }

    /** A server-side diagnostic; it never changes a customer or router. */
    public function sendTestNotification(Customer $customer): string
    {
        return $this->send($customer, [
            'title' => 'SolarNet notifications are working',
            'body' => 'This is a test alert for your SolarNet customer portal account.',
            'url' => '/customer/dashboard',
            'tag' => $this->topic('test', (string) $customer->id),
            'urgency' => 'normal',
            'ttl' => 3600,
        ]);
    }

    /**
     * @param array{title: string, body: string, url: string, tag: string, urgency: string, ttl: int} $message
     */
    private function send(Customer $customer, array $message): string
    {
        if (!$this->isConfigured()) {
            return 'skipped_not_configured';
        }

        $subscriptions = $customer->webPushSubscriptions()->get();
        if ($subscriptions->isEmpty()) {
            return 'skipped_no_subscription';
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
                'TTL' => $message['ttl'],
                'urgency' => $message['urgency'],
                'topic' => $message['tag'],
                'contentType' => 'application/json',
            ]);

            $payload = json_encode([
                'title' => $message['title'],
                'body' => $message['body'],
                'url' => $message['url'],
                'tag' => $message['tag'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $byEndpoint = [];

            foreach ($subscriptions as $stored) {
                $subscription = $subscriptionClass::create([
                    'endpoint' => $stored->endpoint,
                    'keys' => [
                        'p256dh' => $stored->public_key,
                        'auth' => $stored->auth_token,
                    ],
                    'contentEncoding' => $stored->content_encoding,
                ]);
                $webPush->queueNotification($subscription, $payload);
                $byEndpoint[$stored->endpoint] = $stored;
            }

            $sent = 0;
            foreach ($webPush->flush() as $report) {
                /** @var CustomerWebPushSubscription|null $stored */
                $stored = $byEndpoint[(string) $report->getEndpoint()] ?? null;
                if (!$stored) {
                    continue;
                }

                if ($report->isSuccess()) {
                    $stored->forceFill([
                        'last_used_at' => now(),
                        'last_sent_at' => now(),
                        'failed_at' => null,
                        'failure_reason' => null,
                    ])->save();
                    $sent++;
                    continue;
                }

                // Push-provider failure text can include an endpoint URL. Keep
                // a useful but non-sensitive diagnostic code instead.
                $reason = $report->isSubscriptionExpired() ? 'subscription_expired' : 'push_service_rejected';
                if ($report->isSubscriptionExpired()) {
                    $stored->delete();
                } else {
                    $stored->forceFill([
                        'failed_at' => now(),
                        'failure_reason' => $reason,
                    ])->save();
                }
                Log::warning('Customer web push delivery failed', [
                    'customer_id' => $customer->id,
                    'subscription_id' => $stored->id,
                    'expired' => $report->isSubscriptionExpired(),
                    'reason' => $reason,
                ]);
            }

            return $sent > 0 ? 'sent' : 'failed';
        } catch (Throwable $e) {
            Log::warning('Customer web push delivery could not be completed', [
                'customer_id' => $customer->id,
                'subscription_count' => $subscriptions->count(),
                'error_type' => $e::class,
            ]);
            return 'failed';
        }
    }

    private function configurationReason(): string
    {
        if (!class_exists(self::WEB_PUSH_CLASS) || !class_exists(self::SUBSCRIPTION_CLASS)) {
            return 'The Web Push server package has not been installed.';
        }

        return 'Web Push has not been configured on the server.';
    }

    private function topic(string $type, string $identifier): string
    {
        return 'sn-' . $type . '-' . substr(hash('sha256', $identifier), 0, 16);
    }
}
