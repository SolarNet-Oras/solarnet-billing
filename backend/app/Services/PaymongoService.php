<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymongoCheckout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PaymongoService
{
    public function __construct(private InvoiceService $invoices) {}

    public function createGcashCheckout(Invoice $invoice): array
    {
        $invoice->loadMissing('customer');
        $key = config('services.paymongo.secret_key');
        if (!$key) throw new RuntimeException('GCash payments are not configured yet. Ask SolarNet to add PAYMONGO_SECRET_KEY on the server.');
        if ($invoice->balance <= 0) throw new RuntimeException('This invoice is already paid.');
        if (!$invoice->customer?->account_number) throw new RuntimeException('This invoice cannot be paid online because its customer account number is missing.');

        $customer = $invoice->customer;
        $accountNumber = $customer->account_number;
        $reference = 'SLR-' . $accountNumber . '-' . $invoice->invoice_number . '-' . Str::upper(Str::random(8));
        $origin = rtrim(config('app.url'), '/');
        $payload = ['data' => ['attributes' => [
            'line_items' => [[
                'currency' => 'PHP', 'amount' => (int) round($invoice->balance * 100), 'name' => "SolarNet {$accountNumber} - {$invoice->invoice_number}", 'quantity' => 1,
            ]],
            'payment_method_types' => ['gcash'],
            'description' => "SolarNet account {$accountNumber}: {$customer->full_name} - invoice {$invoice->invoice_number}",
            'reference_number' => $reference,
            'success_url' => $origin . '/customer/billing?payment=success',
            'cancel_url' => $origin . '/customer/billing?payment=cancelled',
            'billing' => ['name' => $customer->full_name, 'email' => $customer->email, 'phone' => $customer->contact_number],
        ]]];
        $response = Http::withBasicAuth($key, '')->acceptJson()->post(rtrim(config('services.paymongo.base_url'), '/') . '/checkout_sessions', $payload);
        if (!$response->successful()) throw new RuntimeException('PayMongo could not start GCash checkout: ' . ($response->json('errors.0.detail') ?? $response->body()));
        $data = $response->json('data');
        $checkoutUrl = $data['attributes']['checkout_url'] ?? null;
        if (!$checkoutUrl) throw new RuntimeException('PayMongo did not return a checkout URL.');
        PaymongoCheckout::create(['invoice_id' => $invoice->id, 'customer_id' => $invoice->customer_id, 'account_number' => $accountNumber, 'checkout_session_id' => $data['id'], 'reference_number' => $reference, 'amount' => $invoice->balance]);
        return ['checkout_url' => $checkoutUrl, 'checkout_session_id' => $data['id'], 'reference_number' => $reference, 'account_number' => $accountNumber, 'customer_name' => $customer->full_name, 'invoice_number' => $invoice->invoice_number];
    }

    /**
     * Create a PayMongo Dynamic QR Ph Payment Intent. The QR image is only
     * returned after the browser attaches a QR Ph payment method with the
     * public key; this method never manufactures a QR from a SolarNet URL.
     */
    public function createQrPhPayment(Invoice $invoice): array
    {
        $invoice->loadMissing('customer');
        $secret = config('services.paymongo.secret_key');
        $public = config('services.paymongo.public_key');
        if (!$secret || !$public) {
            throw new RuntimeException('QR Ph payments are not configured. Add PAYMONGO_SECRET_KEY and PAYMONGO_PUBLIC_KEY on the server.');
        }
        if ($invoice->balance <= 0) throw new RuntimeException('This invoice is already paid.');
        if (!$invoice->customer?->account_number) throw new RuntimeException('This invoice cannot be paid online because its customer account number is missing.');

        $customer = $invoice->customer;
        $existing = PaymongoCheckout::query()
            ->where('invoice_id', $invoice->id)
            ->where('checkout_type', 'qr_ph')
            ->whereIn('status', ['pending', 'processing'])
            ->where(function ($query) { $query->whereNull('expires_at')->orWhere('expires_at', '>', now()); })
            ->latest()
            ->first();
        if ($existing) {
            return $this->qrPhResponse($existing, $customer->full_name, $invoice->invoice_number, $public);
        }

        $accountNumber = $customer->account_number;
        $reference = 'SLR-' . $accountNumber . '-' . $invoice->invoice_number . '-' . Str::upper(Str::random(8));
        $amount = (int) round((float) $invoice->balance * 100);
        if ($amount < 100) throw new RuntimeException('QR Ph requires a minimum payment of PHP 1.00.');
        Log::info('PayMongo QR Ph creation started', ['invoice_id' => $invoice->id, 'account_number' => $accountNumber, 'amount' => $invoice->balance]);
        $response = Http::withBasicAuth($secret, '')->acceptJson()->timeout(20)->post(
            rtrim(config('services.paymongo.base_url'), '/') . '/payment_intents',
            ['data' => ['attributes' => [
                'amount' => $amount,
                'currency' => 'PHP',
                'payment_method_allowed' => ['qrph'],
                'description' => "SolarNet {$accountNumber} - {$invoice->invoice_number}",
            ]]],
        );
        if (!$response->successful()) throw new RuntimeException('PayMongo could not create the QR Ph payment: ' . ($response->json('errors.0.detail') ?? $response->body()));
        $data = $response->json('data', []);
        $intentId = $data['id'] ?? null;
        $clientKey = data_get($data, 'attributes.client_key');
        if (!$intentId || !$clientKey) throw new RuntimeException('PayMongo did not return a QR Ph Payment Intent client key.');

        $checkout = PaymongoCheckout::create([
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'account_number' => $accountNumber,
            'checkout_session_id' => 'qrph-' . Str::lower(Str::random(32)),
            'checkout_type' => 'qr_ph',
            'payment_intent_id' => $intentId,
            'payment_intent_client_key' => $clientKey,
            'reference_number' => $reference,
            'amount' => $invoice->balance,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
        ]);
        Log::info('PayMongo QR Ph Payment Intent created', ['invoice_id' => $invoice->id, 'payment_intent_id' => $intentId, 'reference_number' => $reference, 'amount' => $invoice->balance]);
        return $this->qrPhResponse($checkout, $customer->full_name, $invoice->invoice_number, $public);
    }

    /** Save the PayMongo-generated QR image after the public-key attach flow. */
    public function finalizeQrPhAttachment(PaymongoCheckout $checkout, string $paymentMethodId, ?string $imageUrl = null): array
    {
        if ($checkout->checkout_type !== 'qr_ph' || !$checkout->payment_intent_id) throw new RuntimeException('This is not a QR Ph payment.');
        $checkout->loadMissing('invoice.customer');
        if ($checkout->status === 'paid') {
            if ($imageUrl && str_starts_with($imageUrl, 'data:image/')) {
                $checkout->update(['payment_method_id' => $paymentMethodId, 'qr_image_url' => $imageUrl]);
            }
            return $this->qrPhResponse($checkout->fresh(), $checkout->invoice?->customer?->full_name ?? '', $checkout->invoice?->invoice_number ?? '', config('services.paymongo.public_key'));
        }
        $secret = config('services.paymongo.secret_key');
        $response = Http::withBasicAuth($secret, '')->acceptJson()->timeout(20)->get(rtrim(config('services.paymongo.base_url'), '/') . '/payment_intents/' . $checkout->payment_intent_id);
        if (!$response->successful()) throw new RuntimeException('PayMongo could not verify the QR Ph attachment.');
        $attributes = $response->json('data.attributes', []);
        $status = strtolower((string) ($attributes['status'] ?? ''));
        $actualAmount = (int) ($attributes['amount'] ?? 0);
        if ($actualAmount !== (int) round($checkout->amount * 100) || strtoupper((string) ($attributes['currency'] ?? '')) !== 'PHP') {
            throw new RuntimeException('PayMongo QR Ph amount or currency did not match this invoice.');
        }
        $image = data_get($attributes, 'next_action.code.image_url') ?: $imageUrl;
        if (!$image || !str_starts_with($image, 'data:image/')) throw new RuntimeException('PayMongo did not return a QR Ph image.');
        $checkout->update(['payment_method_id' => $paymentMethodId, 'qr_image_url' => $image, 'status' => in_array($status, ['succeeded', 'paid'], true) ? 'processing' : 'pending']);
        return $this->qrPhResponse($checkout->fresh(), $checkout->invoice->customer->full_name, $checkout->invoice->invoice_number, config('services.paymongo.public_key'));
    }

    private function qrPhResponse(PaymongoCheckout $checkout, string $customerName, string $invoiceNumber, ?string $publicKey): array
    {
        return [
            'checkout_id' => $checkout->id,
            'payment_intent_id' => $checkout->payment_intent_id,
            'client_key' => $checkout->payment_intent_client_key,
            'public_key' => $publicKey,
            'base_url' => rtrim((string) config('services.paymongo.base_url'), '/'),
            'payment_method_id' => $checkout->payment_method_id,
            'qr_image_url' => $checkout->qr_image_url,
            'reference_number' => $checkout->reference_number,
            'account_number' => $checkout->account_number,
            'customer_name' => $customerName,
            'invoice_number' => $invoiceNumber,
            'amount' => (float) $checkout->amount,
            'currency' => 'PHP',
            'status' => $checkout->status,
            'expires_at' => optional($checkout->expires_at)->toIso8601String(),
        ];
    }

    public function markPaidByCheckoutId(string $sessionId): void
    {
        DB::transaction(function () use ($sessionId) {
            $checkout = PaymongoCheckout::with('invoice.customer')->where('checkout_session_id', $sessionId)->lockForUpdate()->first();
            if (!$checkout || $checkout->payment_id) return;
            $invoice = $checkout->invoice->fresh();
            if (!$invoice || $invoice->customer_id !== $checkout->customer_id || ($checkout->account_number && $invoice->customer?->account_number !== $checkout->account_number)) {
                throw new RuntimeException('PayMongo checkout account verification failed. No payment was recorded.');
            }
            if ($invoice->balance <= 0) { $checkout->update(['status' => 'paid', 'paid_at' => now()]); return; }
            $payment = $this->invoices->recordPayment($invoice, ['amount' => min($checkout->amount, $invoice->balance), 'payment_method' => 'mobile_money', 'payment_date' => now(), 'transaction_id' => $sessionId, 'reference' => $checkout->reference_number, 'notes' => 'PayMongo GCash checkout | Account ' . $checkout->account_number . ' | ' . $invoice->customer->full_name]);
            $checkout->update(['status' => 'paid', 'paid_at' => now(), 'payment_id' => $payment->id]);
        });
    }

    /** Re-read a QR Ph Payment Intent and record it exactly once. */
    public function reconcileQrPhPayment(string $paymentIntentId, ?string $eventId = null): bool
    {
        $secret = config('services.paymongo.secret_key');
        if (!$secret) return false;
        $checkout = PaymongoCheckout::where('payment_intent_id', $paymentIntentId)->first();
        if (!$checkout || $checkout->checkout_type !== 'qr_ph') return false;
        $response = Http::withBasicAuth($secret, '')->acceptJson()->timeout(20)->get(rtrim(config('services.paymongo.base_url'), '/') . '/payment_intents/' . $paymentIntentId);
        if (!$response->successful()) return false;
        $attributes = $response->json('data.attributes', []);
        $status = strtolower((string) ($attributes['status'] ?? ''));
        if (in_array($status, ['expired', 'failed', 'canceled', 'cancelled'], true)) {
            $checkout->update(['status' => $status === 'canceled' || $status === 'cancelled' ? 'cancelled' : $status]);
            return false;
        }
        if (!in_array($status, ['succeeded', 'paid'], true)) return false;
        $actualAmount = (int) ($attributes['amount'] ?? 0);
        if ($actualAmount !== (int) round($checkout->amount * 100) || strtoupper((string) ($attributes['currency'] ?? '')) !== 'PHP') {
            $checkout->update(['status' => 'amount_mismatch']);
            Log::warning('PayMongo QR Ph amount mismatch', ['payment_intent_id' => $paymentIntentId, 'expected' => $checkout->amount, 'actual_centavos' => $actualAmount]);
            return false;
        }
        $paymongoPaymentId = data_get($attributes, 'payments.0.id') ?: data_get($attributes, 'latest_payment.id') ?: $paymentIntentId;
        return $this->markPaidByQrCheckout($checkout, $paymongoPaymentId, $eventId);
    }

    /** Resolve webhook payloads that identify the PayMongo payment resource. */
    public function reconcileQrPhPaymentResource(string $resourceId, ?string $eventId = null): bool
    {
        if (blank($resourceId)) return false;
        $checkout = PaymongoCheckout::where('paymongo_payment_id', $resourceId)->first();
        if ($checkout?->payment_intent_id) return $this->reconcileQrPhPayment($checkout->payment_intent_id, $eventId);
        $secret = config('services.paymongo.secret_key');
        if (!$secret) return false;
        $response = Http::withBasicAuth($secret, '')->acceptJson()->timeout(20)->get(rtrim(config('services.paymongo.base_url'), '/') . '/payments/' . $resourceId);
        if (!$response->successful()) return false;
        $intentId = data_get($response->json('data.attributes', []), 'payment_intent_id')
            ?? data_get($response->json('data.attributes', []), 'payment_intent.data.id')
            ?? data_get($response->json('data.attributes', []), 'payment_intent.id');
        return $intentId ? $this->reconcileQrPhPayment((string) $intentId, $eventId) : false;
    }

    private function markPaidByQrCheckout(PaymongoCheckout $checkout, string $paymongoPaymentId, ?string $eventId): bool
    {
        return DB::transaction(function () use ($checkout, $paymongoPaymentId, $eventId): bool {
            $locked = PaymongoCheckout::with('invoice.customer')->whereKey($checkout->id)->lockForUpdate()->first();
            if (!$locked) return false;
            if ($locked->payment_id || $locked->status === 'paid') return true;
            $invoice = $locked->invoice?->fresh();
            if (!$invoice || $invoice->customer_id !== $locked->customer_id || ($locked->account_number && $invoice->customer?->account_number !== $locked->account_number)) {
                throw new RuntimeException('PayMongo QR Ph account verification failed. No payment was recorded.');
            }
            if ((float) $invoice->balance > 0) {
                $payment = $this->invoices->recordPayment($invoice, [
                    'amount' => min((float) $locked->amount, (float) $invoice->balance),
                    'payment_method' => 'mobile_money',
                    'payment_date' => now(),
                    'transaction_id' => $paymongoPaymentId,
                    'reference' => $locked->reference_number,
                    'notes' => 'PayMongo QR Ph | Payment Intent ' . $locked->payment_intent_id . ' | Account ' . $locked->account_number,
                ]);
                $locked->payment_id = $payment->id;
            }
            $locked->status = 'paid';
            $locked->paymongo_payment_id = $paymongoPaymentId;
            $locked->webhook_event_id = $eventId ?: $locked->webhook_event_id;
            $locked->paid_at = now();
            $locked->save();
            Log::info('PayMongo QR Ph payment confirmed', ['payment_intent_id' => $locked->payment_intent_id, 'payment_id' => $paymongoPaymentId, 'invoice_id' => $invoice->id, 'amount' => $locked->amount, 'webhook_event_id' => $eventId]);
            return true;
        });
    }

    /** Ask PayMongo directly; no browser redirect or webhook body is trusted as proof of payment. */
    public function reconcileCheckout(string $sessionId): bool
    {
        $key = config('services.paymongo.secret_key');
        if (!$key) return false;
        $response = Http::withBasicAuth($key, '')->acceptJson()->get(rtrim(config('services.paymongo.base_url'), '/') . '/checkout_sessions/' . $sessionId);
        if (!$response->successful()) return false;
        $attributes = $response->json('data.attributes', []);
        $status = strtolower((string) ($attributes['payment_intent']['attributes']['status'] ?? $attributes['status'] ?? ''));
        if (!in_array($status, ['paid', 'succeeded'], true)) return false;
        $this->markPaidByCheckoutId($sessionId);
        return true;
    }

    /**
     * Reconcile recent pending checkouts even when a customer closes PayMongo
     * before returning to SolarNet or a webhook is delayed.
     */
    public function reconcilePendingCheckouts(): array
    {
        $result = ['checked' => 0, 'paid' => 0, 'failed' => 0];

        PaymongoCheckout::query()
            ->whereIn('status', ['pending', 'processing'])
            ->where('created_at', '>=', now()->subDays(2))
            ->orderBy('created_at')
            ->eachById(function (PaymongoCheckout $checkout) use (&$result): void {
                $result['checked']++;
                try {
                    $paid = $checkout->checkout_type === 'qr_ph'
                        ? $this->reconcileQrPhPayment((string) $checkout->payment_intent_id)
                        : $this->reconcileCheckout($checkout->checkout_session_id);
                    if ($paid) {
                        $result['paid']++;
                    }
                } catch (\Throwable $e) {
                    $result['failed']++;
                    report($e);
                }
            });

        return $result;
    }

    /** Verify the authenticated customer's latest checkout after returning from PayMongo. */
    public function reconcileLatestCustomerCheckout(string $customerId): array
    {
        $checkout = PaymongoCheckout::where('customer_id', $customerId)
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->first();
        if (!$checkout) return ['found' => false, 'paid' => false];

        $paid = $checkout->checkout_type === 'qr_ph'
            ? $this->reconcileQrPhPayment((string) $checkout->payment_intent_id)
            : $this->reconcileCheckout($checkout->checkout_session_id);
        return ['found' => true, 'paid' => $paid, 'checkout' => $checkout->fresh()];
    }
}
