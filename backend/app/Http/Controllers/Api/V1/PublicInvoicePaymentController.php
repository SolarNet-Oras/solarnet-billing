<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PaymongoCheckout;
use App\Services\InvoicePaymentLinkService;
use App\Services\MikrotikService;
use App\Services\PaymongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicInvoicePaymentController extends Controller
{
    public function __construct(private InvoicePaymentLinkService $links) {}

    public function show(string $token): JsonResponse
    {
        $invoice = $this->resolve($token);
        return response()->json(['data' => [
            'invoice' => $invoice->only(['id', 'invoice_number', 'issue_date', 'due_date', 'total', 'paid_amount', 'balance', 'status']),
            'customer' => ['full_name' => $invoice->customer?->full_name, 'account_number_masked' => '******' . substr((string) $invoice->customer?->account_number, -4)],
        ]]);
    }

    public function gcash(string $token, PaymongoService $paymongo): JsonResponse
    {
        $invoice = $this->resolve($token);
        try {
            $checkout = $paymongo->createGcashCheckout($invoice, $this->links->url($invoice));
            $this->grantAccess($invoice);
            return response()->json(['data' => $checkout]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reconcileGcash(string $token, PaymongoService $paymongo): JsonResponse
    {
        $invoice = $this->resolve($token);
        $checkout = PaymongoCheckout::where('invoice_id', $invoice->id)->where('checkout_type', '!=', 'qr_ph')->latest()->first();
        return response()->json(['paid' => $checkout ? $paymongo->reconcileCheckout($checkout->checkout_session_id) : false, 'found' => (bool) $checkout]);
    }

    public function qrPh(string $token, PaymongoService $paymongo): JsonResponse
    {
        $invoice = $this->resolve($token);
        try {
            $payment = $paymongo->createQrPhPayment($invoice);
            $this->grantAccess($invoice);
            return response()->json(['data' => $payment]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function attach(Request $request, string $token, string $checkoutId, PaymongoService $paymongo): JsonResponse
    {
        $invoice = $this->resolve($token);
        $data = $request->validate(['payment_method_id' => 'required|string|max:100', 'qr_image_url' => 'nullable|string|max:2000000']);
        $checkout = PaymongoCheckout::where('invoice_id', $invoice->id)->whereKey($checkoutId)->firstOrFail();
        return response()->json(['data' => $paymongo->finalizeQrPhAttachment($checkout, $data['payment_method_id'], $data['qr_image_url'] ?? null)]);
    }

    public function reconcileQrPh(string $token, string $checkoutId, PaymongoService $paymongo): JsonResponse
    {
        $invoice = $this->resolve($token);
        $checkout = PaymongoCheckout::where('invoice_id', $invoice->id)->whereKey($checkoutId)->firstOrFail();
        return response()->json(['paid' => $paymongo->reconcileQrPhPayment((string) $checkout->payment_intent_id), 'payment_status' => $checkout->fresh()->status]);
    }

    private function resolve(string $token): \App\Models\Invoice
    {
        $invoice = $this->links->invoice($token);
        abort_unless($invoice, 404, 'This payment link is invalid or has expired. Request a new invoice email from SolarNet.');
        return $invoice;
    }

    private function grantAccess(\App\Models\Invoice $invoice): void
    {
        if ($invoice->customer) app(MikrotikService::class)->grantTemporaryPaymentCheckoutAccess($invoice->customer, 1440);
    }
}
