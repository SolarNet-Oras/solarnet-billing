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
        $unremittedPayments = Payment::where('collector_id', $request->user()->id)->whereNull('remittance_id');
        $unremitted = (clone $unremittedPayments)->sum('amount');
        $unremittedCash = (clone $unremittedPayments)->where('payment_method', 'cash')->sum('amount');
        return response()->json(['invoices' => $invoices, 'unremitted_amount' => (float) $unremitted, 'unremitted_cash_amount' => (float) $unremittedCash]);
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
        abort_if($customer->hasCompanyOwnedPlan(), 422, 'Company Owned plans do not use early-payment invoices.');
        abort_unless($customer->servicePlan || $customer->monthly_fee > 0, 422, 'This client has no billable service plan.');
        $openInvoice = Invoice::where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->whereIn('status', ['draft', 'sent', 'partial', 'overdue'])
            ->first();
        abort_if($openInvoice, 422, 'This client already has an unpaid invoice. Use that invoice for payment.');

        $start = Carbon::today();
        $invoice = $invoices->generateInvoice($customer, $start, $start->copy()->addMonthNoOverflow()->subDay(), [], now(), now());
        $invoice->update(['notes' => 'Early payment invoice created by collector.']);
        $invoices->markAsSent($invoice->fresh(['customer', 'items', 'payments']));

        return response()->json(['message' => 'Early payment invoice created.', 'invoice' => $invoice->fresh(['customer', 'items'])], 201);
    }

    public function collect(Request $request, string $invoiceId, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer',
            'reference' => 'nullable|string|max:255',
            'transaction_id' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'payer_signature' => 'required_if:payment_method,cash|string|starts_with:data:image/png;base64,|max:500000',
            'payer_signature_fingerprint' => 'required_if:payment_method,cash|string|regex:/^[01]{32}$/',
            'signature_signer_type' => 'required_if:payment_method,cash|in:client,family',
            'signature_signer_name' => 'required_if:signature_signer_type,family|nullable|string|max:120',
        ]);
        $invoice = Invoice::findOrFail($invoiceId);
        abort_unless($invoice->balance > 0 && $invoice->due_date->lte(today()), 422, 'Collectors may record payment only for a due invoice.');
        abort_if((float) $data['amount'] > (float) $invoice->balance, 422, 'Payment amount exceeds invoice balance.');
        $data['collector_id'] = $request->user()->id;
        $data['payment_date'] = now()->toDateString();

        if ($data['payment_method'] === 'cash' && $invoice->customer && $data['signature_signer_type'] === 'client') {
            $reference = $invoice->customer->cash_signature_fingerprint;
            if ($reference) {
                $data['payer_signature_similarity'] = $this->signatureSimilarity($reference, $data['payer_signature_fingerprint']);
                abort_if($data['payer_signature_similarity'] < 0.5, 422, 'The client signature does not match the saved reference closely enough. Ask the client to sign again or select the authorized family signer option.');
            } else {
                $invoice->customer->update([
                    'cash_signature_reference' => $data['payer_signature'],
                    'cash_signature_fingerprint' => $data['payer_signature_fingerprint'],
                    'cash_signature_reference_at' => now(),
                ]);
                $data['payer_signature_similarity'] = 1;
            }
        }

        $payment = $invoices->recordPayment($invoice, $data);
        return response()->json(['message' => 'Payment received and added to your pending remittance.', 'payment' => $payment], 201);
    }

    private function signatureSimilarity(string $reference, string $candidate): float
    {
        $overlap = 0;
        $ink = 0;
        for ($index = 0; $index < 32; $index++) {
            $a = $reference[$index] ?? '0';
            $b = $candidate[$index] ?? '0';
            if ($a === '1' || $b === '1') $ink++;
            if ($a === '1' && $b === '1') $overlap++;
        }
        return $ink ? $overlap / $ink : 0.0;
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
            $remittance = Remittance::create([
                'collector_id' => $request->user()->id,
                'declared_amount' => $payments->sum('amount'),
                'notes' => $data['notes'] ?? null,
                'submitted_at' => now(),
            ]);
            Payment::whereIn('id', $payments->pluck('id'))->update(['remittance_id' => $remittance->id]);
            return $remittance->load('payments');
        });
        return response()->json(['message' => 'Remittance submitted. An administrator or cashier must liquidate the cash before validation.', 'remittance' => $remittance], 201);
    }

    /** Physical cash is counted by the receiving office, never by the collector. */
    public function liquidate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'cash_breakdown' => 'required|array',
            'cash_breakdown.*.denomination' => 'required|integer|in:1000,500,200,100,50,20,10,5,1',
            'cash_breakdown.*.count' => 'required|integer|min:0|max:100000',
        ]);

        $remittance = DB::transaction(function () use ($request, $id, $data) {
            $remittance = Remittance::with('payments')->lockForUpdate()->findOrFail($id);
            abort_if($remittance->status !== 'submitted', 422, 'This remittance has already been validated.');
            $denominations = [1000, 500, 200, 100, 50, 20, 10, 5, 1];
            $counts = collect($data['cash_breakdown'])->mapWithKeys(fn (array $row) => [(int) $row['denomination'] => (int) $row['count']]);
            $breakdown = collect($denominations)->map(fn (int $denomination) => [
                'denomination' => $denomination,
                'count' => (int) ($counts[$denomination] ?? 0),
                'amount' => $denomination * (int) ($counts[$denomination] ?? 0),
            ])->all();
            $cashCounted = collect($breakdown)->sum('amount');
            $cashExpected = (float) $remittance->payments->where('payment_method', 'cash')->sum('amount');
            abort_unless((int) round($cashCounted * 100) === (int) round($cashExpected * 100), 422, "Cash count must match collected cash of ₱" . number_format($cashExpected, 2) . '.');

            $remittance->update([
                'liquidated_by' => $request->user()->id,
                'cash_counted_amount' => $cashCounted,
                'cash_breakdown' => $breakdown,
                'liquidated_at' => now(),
            ]);
            return $remittance->fresh(['collector', 'liquidator', 'payments']);
        });

        return response()->json(['message' => 'Cash liquidation matches the collector cash total. You may now validate this remittance.', 'remittance' => $remittance]);
    }

    public function index(): JsonResponse
    {
        return response()->json(Remittance::with(['collector:id,name,email', 'liquidator:id,name,email', 'receiver:id,name,email', 'payments:id,remittance_id,payment_method,amount,payment_number'])->latest('submitted_at')->paginate(50));
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['received_amount' => 'required|numeric|min:0', 'notes' => 'nullable|string|max:1000']);
        $remittance = Remittance::with('payments')->findOrFail($id);
        abort_if($remittance->status !== 'submitted', 422, 'This remittance has already been verified.');
        abort_unless($remittance->liquidated_at && $remittance->liquidated_by, 422, 'Cash must be liquidated by an administrator or cashier before validation.');
        $cashExpected = (float) $remittance->payments->where('payment_method', 'cash')->sum('amount');
        abort_unless((int) round((float) $remittance->cash_counted_amount * 100) === (int) round($cashExpected * 100), 422, 'The stored cash liquidation does not match recorded cash payments.');
        $remittance->update(['received_by' => $request->user()->id, 'received_amount' => $data['received_amount'], 'status' => (float) $data['received_amount'] === (float) $remittance->declared_amount ? 'received' : 'discrepancy', 'notes' => trim(($remittance->notes ? $remittance->notes."\n" : '').($data['notes'] ?? '')), 'received_at' => now()]);
        return response()->json(['message' => $remittance->status === 'received' ? 'Remittance received and verified.' : 'Remittance recorded with a discrepancy for review.', 'remittance' => $remittance]);
    }
}
