<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\PaymongoCheckout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    /** Verify the authenticated customer's latest checkout after returning from PayMongo. */
    public function reconcileLatestCustomerCheckout(string $customerId): array
    {
        $checkout = PaymongoCheckout::where('customer_id', $customerId)
            ->whereIn('status', ['pending', 'processing'])
            ->latest()
            ->first();
        if (!$checkout) return ['found' => false, 'paid' => false];

        return ['found' => true, 'paid' => $this->reconcileCheckout($checkout->checkout_session_id), 'checkout' => $checkout->fresh()];
    }
}
