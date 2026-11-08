<?php

namespace App\Services;

use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use App\Models\TenantSubscriptionPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class SaasSubscriptionPaymentService
{
    public function checkout(TenantSubscription $subscription): array
    {
        $subscription->loadMissing(['tenant', 'plan']);
        $plan = $subscription->plan;
        $tenant = $subscription->tenant;
        abort_if(! $plan || ! $plan->is_active, 422, 'The subscription plan is unavailable.');
        $key = config('services.paymongo.secret_key');
        if (! $key) throw new RuntimeException('Online subscription payments are not configured.');

        $reference = 'SAAS-'.Str::upper(Str::random(16));
        $payment = TenantSubscriptionPayment::create([
            'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'provider' => 'paymongo', 'provider_reference' => $reference,
            'idempotency_key' => 'saas-checkout:'.Str::uuid(), 'amount' => $plan->amount,
            'currency' => $plan->currency, 'status' => 'pending',
        ]);
        $returnUrl = 'https://'.config('saas.domains.app').'/subscription';
        $payload = ['data' => ['attributes' => [
            'line_items' => [[
                'currency' => $plan->currency, 'amount' => (int) round(((float) $plan->amount) * 100),
                'name' => $plan->name, 'quantity' => 1,
            ]],
            'payment_method_types' => ['card', 'gcash', 'paymaya', 'dob'],
            'description' => "{$tenant->name} ISP workspace subscription",
            'reference_number' => $reference,
            'success_url' => $returnUrl.'?payment=success', 'cancel_url' => $returnUrl.'?payment=cancelled',
            'billing' => ['name' => $tenant->contact_name ?: $tenant->name, 'email' => $tenant->contact_email, 'phone' => $tenant->contact_phone],
        ]]];
        $response = Http::withBasicAuth($key, '')->acceptJson()
            ->post(rtrim(config('services.paymongo.base_url'), '/').'/checkout_sessions', $payload);
        if (! $response->successful()) {
            $payment->update(['status' => 'failed', 'failure_reason' => data_get($response->json(), 'errors.0.detail') ?: 'checkout_creation_failed']);
            throw new RuntimeException('PayMongo could not start the subscription checkout.');
        }
        $session = $response->json('data');
        $url = data_get($session, 'attributes.checkout_url');
        if (! $url) throw new RuntimeException('PayMongo did not return a checkout URL.');
        $payment->update(['provider_payment_id' => data_get($session, 'id'), 'provider_payload' => ['checkout_url' => $url]]);
        return ['checkout_url' => $url, 'reference' => $reference];
    }

    public function qrPh(TenantSubscription $subscription): array
    {
        $subscription->loadMissing(['tenant', 'plan']);
        $plan = $subscription->plan;
        $tenant = $subscription->tenant;
        abort_if(! $plan || ! $plan->is_active, 422, 'The subscription plan is unavailable.');
        $secret = config('services.paymongo.secret_key');
        $public = config('services.paymongo.public_key');
        if (! $secret || ! $public) throw new RuntimeException('QRPh subscription payments are not configured.');

        $existing = TenantSubscriptionPayment::query()
            ->where('subscription_id', $subscription->id)->where('provider', 'paymongo_qrph')
            ->where('status', 'pending')->where('created_at', '>', now()->subMinutes(30))->latest()->first();
        if ($existing && filled(data_get($existing->provider_payload, 'qr_image_url'))) return $this->qrResponse($existing);

        $reference = 'SAAS-QR-'.Str::upper(Str::random(12));
        $intent = Http::withBasicAuth($secret, '')->acceptJson()->timeout(20)
            ->post(rtrim(config('services.paymongo.base_url'), '/').'/payment_intents', ['data' => ['attributes' => [
                'amount' => (int) round(((float) $plan->amount) * 100), 'currency' => $plan->currency,
                'payment_method_allowed' => ['qrph'], 'description' => "{$tenant->name} ISP workspace subscription",
            ]]]);
        if (! $intent->successful()) throw new RuntimeException('PayMongo could not create the subscription QRPh payment.');
        $intentId = $intent->json('data.id');
        $clientKey = $intent->json('data.attributes.client_key');
        if (! $intentId || ! $clientKey) throw new RuntimeException('PayMongo did not return QRPh payment credentials.');

        $method = Http::withBasicAuth($public, '')->acceptJson()->timeout(20)
            ->post(rtrim(config('services.paymongo.base_url'), '/').'/payment_methods', ['data' => ['attributes' => ['type' => 'qrph']]]);
        $methodId = $method->json('data.id');
        if (! $method->successful() || ! $methodId) throw new RuntimeException('PayMongo could not create the QRPh payment method.');
        $attachment = Http::withBasicAuth($public, '')->acceptJson()->timeout(20)
            ->post(rtrim(config('services.paymongo.base_url'), '/').'/payment_intents/'.$intentId.'/attach', ['data' => ['attributes' => [
                'payment_method' => $methodId, 'client_key' => $clientKey,
            ]]]);
        $image = $attachment->json('data.attributes.next_action.code.image_url');
        if (! $attachment->successful() || ! is_string($image) || ! str_starts_with($image, 'data:image/')) {
            throw new RuntimeException('PayMongo did not return a valid subscription QRPh image.');
        }
        $payment = TenantSubscriptionPayment::create([
            'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'plan_id' => $plan->id,
            'provider' => 'paymongo_qrph', 'provider_payment_id' => $intentId, 'provider_reference' => $reference,
            'idempotency_key' => 'saas-qrph:'.Str::uuid(), 'amount' => $plan->amount, 'currency' => $plan->currency,
            'status' => 'pending', 'provider_payload' => ['qr_image_url' => $image, 'payment_method_id' => $methodId, 'expires_at' => now()->addMinutes(30)->toIso8601String()],
        ]);
        return $this->qrResponse($payment);
    }

    public function receivePaymentIntent(string $intentId, string $eventId = ''): bool
    {
        $payment = TenantSubscriptionPayment::query()->where('provider', 'paymongo_qrph')->where('provider_payment_id', $intentId)->first();
        if (! $payment) return false;
        $response = Http::withBasicAuth(config('services.paymongo.secret_key'), '')->acceptJson()->timeout(20)
            ->get(rtrim(config('services.paymongo.base_url'), '/').'/payment_intents/'.$intentId);
        if (! $response->successful()) return true;
        $status = strtolower((string) $response->json('data.attributes.status'));
        if (in_array($status, ['paid', 'succeeded'], true)) $this->activate($payment, $eventId, $response->json('data'));
        return true;
    }

    public function receiveCheckout(string $sessionId, string $eventId = ''): bool
    {
        $payment = TenantSubscriptionPayment::query()->where('provider', 'paymongo')->where('provider_payment_id', $sessionId)->first();
        if (! $payment) return false;
        $key = config('services.paymongo.secret_key');
        $response = Http::withBasicAuth($key, '')->acceptJson()->get(rtrim(config('services.paymongo.base_url'), '/').'/checkout_sessions/'.$sessionId);
        if (! $response->successful()) return true;
        $attributes = $response->json('data.attributes', []);
        $status = strtolower((string) (data_get($attributes, 'payment_intent.attributes.status') ?? data_get($attributes, 'status') ?? ''));
        if (! in_array($status, ['succeeded', 'paid'], true)) return true;

        $this->activate($payment, $eventId, $response->json('data'));
        return true;
    }

    private function activate(TenantSubscriptionPayment $payment, string $eventId, array $providerPayload): void
    {
        DB::transaction(function () use ($payment, $eventId, $providerPayload): void {
            $locked = TenantSubscriptionPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === 'paid') return;
            $subscription = TenantSubscription::query()->with('plan')->lockForUpdate()->findOrFail($locked->subscription_id);
            $now = now();
            $periodStart = $now->copy();
            foreach ([$subscription->trial_ends_at, $subscription->current_period_ends_at] as $protectedUntil) {
                if ($protectedUntil && $protectedUntil->greaterThan($periodStart)) $periodStart = $protectedUntil->copy();
            }
            $periodEnd = $periodStart->copy()->addMonthNoOverflow();
            $from = $subscription->status;
            $locked->update(['status' => 'paid', 'verified_at' => $now, 'provider_payload' => $providerPayload]);
            $subscription->update([
                'status' => 'active', 'payment_status' => 'paid', 'current_period_starts_at' => $periodStart,
                'current_period_ends_at' => $periodEnd, 'next_payment_due_at' => $periodEnd,
                'last_successful_payment_at' => $now, 'payment_provider' => 'paymongo', 'payment_reference' => $locked->provider_reference,
            ]);
            TenantSubscriptionEvent::firstOrCreate(
                ['idempotency_key' => $eventId ? 'paymongo:'.$eventId : 'paymongo-session:'.$locked->provider_payment_id],
                ['tenant_id' => $locked->tenant_id, 'subscription_id' => $subscription->id, 'event_type' => 'payment_confirmed',
                 'from_status' => $from, 'to_status' => 'active', 'source' => 'paymongo_webhook',
                 'context' => ['payment_id' => $locked->id], 'occurred_at' => $now]
            );
        }, 3);
    }

    private function qrResponse(TenantSubscriptionPayment $payment): array
    {
        return ['payment_id' => $payment->id, 'reference' => $payment->provider_reference,
            'amount' => (float) $payment->amount, 'currency' => $payment->currency,
            'qr_image_url' => data_get($payment->provider_payload, 'qr_image_url'),
            'expires_at' => data_get($payment->provider_payload, 'expires_at')];
    }
}
