<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\CustomerProfileChangeRequest;
use App\Models\ServicePlan;
use App\Services\CustomerPortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

        $action = null;
        if ($contains(['update my information', 'change email', 'update email', 'change phone', 'update phone', 'contact information'])) {
            $action = 'contact_update';
            $answer = 'To protect your account, enter the exact full name and account number already registered, then provide the new email and/or phone number and confirm the change. I cannot change your name, balance, or service status.';
        } elseif ($contains(['upgrade', 'downgrade', 'change plan', 'promo rate', 'promotional rate'])) {
            $action = 'plan_request';
            $answer = 'Plan upgrades, downgrades, and promotional-rate requests require SolarNet approval. Verify the exact registered name and account number, choose an available plan, and confirm the request. Your current plan stays unchanged until staff approval.';
        } elseif ($restricted) {
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
            'action' => $action,
            'limitations' => ['read_only' => true, 'customer_scoped' => true, 'account_commands' => false],
        ]]);
    }

    public function updateContact(Request $request): JsonResponse
    {
        $customer = $this->tokens->authenticate($request);
        if (! $customer) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email:rfc', 'max:255', Rule::unique('customers', 'email')->ignore($customer->id)],
            'contact_number' => ['nullable', 'regex:/^(?:\+63|0)9\d{9}$/'],
            'confirmed' => ['accepted'],
        ]);
        $this->verifyIdentity($customer->full_name, $customer->account_number, $data['full_name'], $data['account_number']);
        if (blank($data['email'] ?? null) && blank($data['contact_number'] ?? null)) {
            return response()->json(['status' => 'error', 'message' => 'Enter a valid new email address or Philippine mobile number.'], 422);
        }
        $changes = [];
        if (filled($data['email'] ?? null)) $changes['email'] = mb_strtolower(trim($data['email']));
        if (filled($data['contact_number'] ?? null)) $changes['contact_number'] = trim($data['contact_number']);
        $customer->update($changes);
        return response()->json(['status' => 'success', 'message' => 'Your verified contact information was updated.', 'data' => ['email' => $customer->fresh()->email, 'contact_number' => $customer->fresh()->contact_number]]);
    }

    public function requestPlan(Request $request): JsonResponse
    {
        $customer = $this->tokens->authenticate($request);
        if (! $customer) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
            'service_plan_id' => ['required', 'uuid'],
            'confirmed' => ['accepted'],
        ]);
        $this->verifyIdentity($customer->full_name, $customer->account_number, $data['full_name'], $data['account_number']);
        $plan = ServicePlan::query()->whereKey($data['service_plan_id'])->where('is_active', true)->whereRaw("LOWER(name) NOT LIKE '%company owned%'")->firstOrFail();
        if ($plan->id === $customer->service_plan_id) return response()->json(['status' => 'error', 'message' => 'Choose a different plan or promotional rate.'], 422);
        $change = CustomerProfileChangeRequest::updateOrCreate(
            ['customer_id' => $customer->id, 'status' => 'pending'],
            ['requested_full_name' => null, 'requested_service_plan_id' => $plan->id, 'reviewed_by' => null, 'reviewed_at' => null, 'review_notes' => null],
        );
        return response()->json(['status' => 'success', 'message' => 'Your plan or promotional-rate request was submitted for SolarNet approval.', 'data' => ['request_id' => $change->id, 'plan' => ['id' => $plan->id, 'name' => $plan->name, 'price' => (float) $plan->price]]], 201);
    }

    private function verifyIdentity(string $actualName, string $actualAccount, string $name, string $account): void
    {
        $normalize = fn (string $value): string => mb_strtolower(preg_replace('/\s+/', ' ', trim($value)) ?? '');
        abort_unless(hash_equals($normalize($actualName), $normalize($name)) && hash_equals(trim($actualAccount), trim($account)), 422, 'The full name and account number must exactly match the signed-in SolarNet account.');
    }
}
