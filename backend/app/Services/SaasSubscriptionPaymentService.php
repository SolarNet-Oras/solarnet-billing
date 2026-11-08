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

        DB::transaction(function () use ($payment, $eventId, $response): void {
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
            $locked->update(['status' => 'paid', 'verified_at' => $now, 'provider_payload' => $response->json('data')]);
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
        return true;
    }
}
