<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Services\CustomerPortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerAssistantController extends Controller
{
    public function __construct(private CustomerPortalTokenService $tokens) {}

    public function chat(Request $request): JsonResponse
    {
        $customer = $this->tokens->authenticate($request);
        if (! $customer) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);

        $data = $request->validate(['message' => ['required', 'string', 'max:500']]);
        $message = mb_strtolower(trim($data['message']));
        $open = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->get(['id', 'invoice_number', 'balance', 'due_date', 'status']);
        $balance = round((float) $open->sum('balance'), 2);
        $invoice = $open->first();

        $contains = fn (array $words): bool => collect($words)->contains(fn ($word) => str_contains($message, $word));
        $restricted = $contains(['unsuspend', 'restore my internet', 'discount', 'cancel invoice', 'delete invoice', 'change balance', 'waive']);

        if ($restricted) {
            $answer = 'I cannot restore or unsuspend service, change billing, apply discounts, cancel invoices, or run account commands. I can only explain the status shown on your own SolarNet account and help you use the official payment or support flow.';
        } elseif ($contains(['pay', 'qr', 'gcash', 'bank'])) {
            $answer = $invoice
                ? 'Your oldest open billing is '.$invoice->invoice_number.' with a balance of ₱'.number_format((float) $invoice->balance, 2).'. I can display its secure QRPh code below. Payment is posted only after provider confirmation.'
                : 'Your account has no open billing to pay right now.';
        } elseif ($contains(['balance', 'owe', 'outstanding', 'billing', 'invoice'])) {
            $answer = $balance > 0
                ? 'Your total open billing is ₱'.number_format($balance, 2).' across '.$open->count().' open billing record(s).'
                : 'Your account currently has no open billing balance.';
        } elseif ($contains(['status', 'active', 'suspend', 'connection'])) {
            $answer = 'Your current service status is '.strtoupper((string) $customer->status).'. Your open billing balance is ₱'.number_format($balance, 2).'. This assistant cannot change that status.';
        } elseif ($contains(['internet', 'offline', 'slow', 'wifi', 'los', 'pon', 'router'])) {
            $answer = 'I can help with safe checks only. Check the Power, PON, LOS, Internet/WAN, and Wi-Fi lights without pressing reset. If LOS is red, PON is off, or the issue continues, use SolarNet technical support. I cannot change your router or restore service from chat.';
        } elseif ($contains(['hello', 'hi', 'hey', 'kumusta', 'joke', 'fun'])) {
            $answer = 'Hello! I am SolarNet’s read-only customer assistant. Tiny internet joke: why did the router need coffee? It lost its connection. 🙂 You can ask me about your balance, account status, payment, or an internet concern.';
        } else {
            $answer = 'I can help with your own account status, open billing balance, how to pay through QRPh/GCash/banks, and basic internet concerns. I cannot access other customers or perform account changes.';
        }

        return response()->json(['status' => 'success', 'data' => [
            'assistant' => $answer,
            'account' => ['status' => $customer->status, 'open_balance' => $balance, 'open_billing_count' => $open->count()],
            'payment_invoice' => $contains(['pay', 'qr', 'gcash', 'bank']) && $invoice ? [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'balance' => (float) $invoice->balance,
            ] : null,
            'limitations' => ['read_only' => true, 'customer_scoped' => true, 'account_commands' => false],
        ]]);
    }
}
