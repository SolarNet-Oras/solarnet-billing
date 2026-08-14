<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Customer;
use App\Models\CustomerProfileChangeRequest;
use App\Models\Payment;
use App\Models\PaymongoCheckout;
use App\Models\Remittance;
use App\Models\ServicePlan;
use App\Services\InvoiceService;
use App\Services\PaymongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class RemittanceController extends Controller
{
    public function collectorDashboard(Request $request): JsonResponse
    {
        $invoices = Invoice::with('customer:id,account_number,full_name,address,contact_number')
            ->where('balance', '>', 0)->whereDate('due_date', '<=', today())
            ->whereIn('status', ['sent', 'overdue', 'partial'])
            ->orderBy('due_date')->paginate($request->integer('per_page', 50));
        $invoices->getCollection()->transform(function (Invoice $invoice) {
            $invoice->setAttribute('previous_balance', (float) Invoice::query()
                ->where('customer_id', $invoice->customer_id)
                ->whereKeyNot($invoice->id)
                ->where('balance', '>', 0)
                ->whereDate('due_date', '<', $invoice->due_date)
                ->sum('balance'));
            return $invoice;
        });
        $unremitted = Payment::where('collector_id', $request->user()->id)->whereNull('remittance_id')->sum('amount');
        return response()->json(['invoices' => $invoices, 'unremitted_amount' => (float) $unremitted]);
    }

    /** Locations are limited to non-deleted customer records with confirmed coordinates. */
    public function collectorLocations(): JsonResponse
    {
        $customers = \App\Models\Customer::query()
            ->whereNotNull('gps_coordinates')
            ->orderBy('full_name')
            ->get(['id', 'account_number', 'full_name', 'address', 'status', 'gps_coordinates'])
            ->filter(function (\App\Models\Customer $customer): bool {
                $coordinates = $customer->gps_coordinates;
                return is_array($coordinates)
                    && is_numeric($coordinates['latitude'] ?? null)
                    && is_numeric($coordinates['longitude'] ?? null);
            })
            ->values()
            ->map(fn (\App\Models\Customer $customer) => [
                'id' => $customer->id,
                'account_number' => $customer->account_number,
                'full_name' => $customer->full_name,
                'address' => $customer->address,
                'status' => $customer->status,
                'latitude' => (float) $customer->gps_coordinates['latitude'],
                'longitude' => (float) $customer->gps_coordinates['longitude'],
            ]);

        return response()->json(['data' => $customers]);
    }

    /** Read-only client lookup for collection staff. */
    public function collectorClients(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $customers = Customer::with('servicePlan:id,name,price,download_speed,upload_speed')
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($term) {
                $query->where('full_name', 'ilike', "%{$term}%")
                    ->orWhere('account_number', 'ilike', "%{$term}%")
                    ->orWhere('address', 'ilike', "%{$term}%")
                    ->orWhere('contact_number', 'ilike', "%{$term}%");
            }))
            ->orderBy('full_name')
            ->limit(20)
            ->get(['id', 'account_number', 'full_name', 'address', 'contact_number', 'status', 'service_plan_id', 'gps_coordinates']);

        return response()->json([
            'data' => $customers,
            'service_plans' => ServicePlan::where('is_active', true)
                ->whereRaw("LOWER(name) NOT LIKE '%company owned%'")
                ->orderBy('price')
                ->get(['id', 'name', 'price', 'download_speed', 'upload_speed']),
        ]);
    }

    /** Collectors may update only an installation point, never account status or profile fields. */
    public function updateCollectorLocation(Request $request, string $customerId): JsonResponse
    {
        $data = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy_meters' => 'nullable|numeric|min:0|max:5000',
        ]);
        $customer = Customer::findOrFail($customerId);
        $customer->update([
            'gps_coordinates' => ['latitude' => (float) $data['latitude'], 'longitude' => (float) $data['longitude']],
            'location_status' => 'confirmed',
            'location_source' => 'collector_device',
            'location_accuracy_meters' => $data['accuracy_meters'] ?? null,
            'location_confirmed_at' => now(),
        ]);

        return response()->json(['message' => 'Client installation coordinates updated.', 'customer' => $customer->fresh()]);
    }

    /** Queue a plan upgrade/downgrade for administrator approval. */
    public function requestCollectorPlanChange(Request $request, string $customerId): JsonResponse
    {
        $data = $request->validate(['service_plan_id' => 'required|uuid|exists:service_plans,id']);
        $customer = Customer::findOrFail($customerId);
        abort_if($customer->service_plan_id === $data['service_plan_id'], 422, 'Choose a different service plan.');
        abort_unless(ServicePlan::whereKey($data['service_plan_id'])
            ->where('is_active', true)
            ->whereRaw("LOWER(name) NOT LIKE '%company owned%'")
            ->exists(), 422, 'The selected service plan is not available.');

        $change = CustomerProfileChangeRequest::updateOrCreate(
            ['customer_id' => $customer->id, 'status' => 'pending'],
            ['requested_full_name' => null, 'requested_service_plan_id' => $data['service_plan_id'], 'reviewed_by' => null, 'reviewed_at' => null, 'review_notes' => null],
        );

        return response()->json(['message' => 'Plan change request sent for administrator approval.', 'request' => $change->fresh('requestedServicePlan')], 201);
    }

    /** Create one payable future-period invoice for a client before it becomes due. */
    public function createCollectorEarlyInvoice(string $customerId, InvoiceService $invoices): JsonResponse
    {
        $customer = Customer::with('servicePlan')->findOrFail($customerId);
        abort_unless($customer->servicePlan || $customer->monthly_fee > 0, 422, 'This client has no billable service plan.');
        $openInvoice = Invoice::where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->whereIn('status', ['draft', 'sent', 'partial', 'overdue'])
            ->first();
        abort_if($openInvoice, 422, 'This client already has an unpaid invoice. Use that invoice for payment.');

        $start = Carbon::today();
        $invoice = $invoices->generateInvoice($customer, $start, $start->copy()->addMonthNoOverflow()->subDay(), [], now(), now());
        $invoice->update(['status' => 'sent', 'notes' => 'Early payment invoice created by collector.']);

        return response()->json(['message' => 'Early payment invoice created.', 'invoice' => $invoice->fresh(['customer', 'items'])], 201);
    }

    public function collect(Request $request, string $invoiceId, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,mobile_money',
            'reference' => 'nullable|string|max:255',
            'transaction_id' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
        ]);
        $invoice = Invoice::findOrFail($invoiceId);
        abort_unless($invoice->balance > 0 && $invoice->due_date->lte(today()), 422, 'Collectors may record payment only for a due invoice.');
        abort_if((float) $data['amount'] > (float) $invoice->balance, 422, 'Payment amount exceeds invoice balance.');
        $data['collector_id'] = $request->user()->id;
        $data['payment_date'] = now()->toDateString();
        $payment = $invoices->recordPayment($invoice, $data);
        return response()->json(['message' => 'Payment received and added to your pending remittance.', 'payment' => $payment], 201);
    }

    /** Start a client-specific GCash checkout. The API secret remains server-side. */
    public function startGcashCheckout(string $invoiceId, PaymongoService $paymongo): JsonResponse
    {
        $invoice = Invoice::with('customer')->findOrFail($invoiceId);
        abort_unless($invoice->balance > 0 && $invoice->due_date->lte(today()), 422, 'Collectors may request online payment only for a due invoice.');

        try {
            return response()->json(['message' => 'PayMongo GCash checkout created.', 'checkout' => $paymongo->createGcashCheckout($invoice)]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Re-read PayMongo rather than trusting a browser return or payment screenshot. */
    public function reconcileGcashCheckout(string $invoiceId, string $checkoutId, PaymongoService $paymongo): JsonResponse
    {
        $checkout = PaymongoCheckout::where('invoice_id', $invoiceId)->where('checkout_session_id', $checkoutId)->firstOrFail();
        $paid = $paymongo->reconcileCheckout($checkout->checkout_session_id);
        return response()->json(['paid' => $paid, 'checkout_status' => $checkout->fresh()->status]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);
        $remittance = DB::transaction(function () use ($request, $data) {
            $payments = Payment::where('collector_id', $request->user()->id)->whereNull('remittance_id')->lockForUpdate()->get();
            abort_if($payments->isEmpty(), 422, 'There are no unremitted collector payments.');
            $remittance = Remittance::create(['collector_id' => $request->user()->id, 'declared_amount' => $payments->sum('amount'), 'notes' => $data['notes'] ?? null, 'submitted_at' => now()]);
            Payment::whereIn('id', $payments->pluck('id'))->update(['remittance_id' => $remittance->id]);
            return $remittance->load('payments');
        });
        return response()->json(['message' => 'Remittance submitted for verification.', 'remittance' => $remittance], 201);
    }

    public function index(): JsonResponse
    {
        return response()->json(Remittance::with(['collector:id,name,email', 'receiver:id,name,email', 'payments:id,remittance_id,payment_method,amount,payment_number'])->latest('submitted_at')->paginate(50));
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['received_amount' => 'required|numeric|min:0', 'notes' => 'nullable|string|max:1000']);
        $remittance = Remittance::findOrFail($id);
        abort_if($remittance->status !== 'submitted', 422, 'This remittance has already been verified.');
        $remittance->update(['received_by' => $request->user()->id, 'received_amount' => $data['received_amount'], 'status' => (float) $data['received_amount'] === (float) $remittance->declared_amount ? 'received' : 'discrepancy', 'notes' => trim(($remittance->notes ? $remittance->notes."\n" : '').($data['notes'] ?? '')), 'received_at' => now()]);
        return response()->json(['message' => $remittance->status === 'received' ? 'Remittance received and verified.' : 'Remittance recorded with a discrepancy for review.', 'remittance' => $remittance]);
    }
}
