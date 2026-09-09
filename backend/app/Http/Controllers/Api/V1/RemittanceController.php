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
use App\Models\User;
use App\Models\FinancialEntry;
use App\Services\CashDenominationService;
use App\Services\InvoiceService;
use App\Services\PaymongoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class RemittanceController extends Controller
{
    public function collectorDashboard(Request $request): JsonResponse
    {
        $today = now(config('app.timezone', 'Asia/Manila'))->startOfDay();
        $perPage = min(max($request->integer('per_page', 200), 1), 200);
        $search = trim((string) $request->query('q', ''));
        $sort = in_array($request->query('sort'), ['due_date', 'address'], true)
            ? (string) $request->query('sort')
            : 'due_date';

        $invoiceQuery = Invoice::with('customer:id,account_number,full_name,address,contact_number')
            ->where('balance', '>', 0)
            ->whereDate('due_date', '<=', $today)
            ->whereIn('status', ['sent', 'overdue', 'partial'])
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('invoice_number', 'ilike', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer
                        ->where('full_name', 'ilike', "%{$search}%")
                        ->orWhere('account_number', 'ilike', "%{$search}%")
                        ->orWhere('address', 'ilike', "%{$search}%"));
            }));

        if ($sort === 'address') {
            $invoiceQuery->orderBy(
                Customer::select('address')->whereColumn('customers.id', 'invoices.customer_id')
            )->orderBy('due_date');
        } else {
            $invoiceQuery->orderBy('due_date')->orderBy(
                Customer::select('address')->whereColumn('customers.id', 'invoices.customer_id')
            );
        }

        $invoices = $invoiceQuery->orderBy('invoice_number')->paginate($perPage);
        $invoices->getCollection()->transform(function (Invoice $invoice) {
            // A due date is a Philippine calendar date, not a UTC timestamp.
            $invoice->setAttribute('due_date_local', $invoice->due_date?->format('Y-m-d'));
            $invoice->setAttribute('previous_balance', (float) Invoice::query()
                ->where('customer_id', $invoice->customer_id)
                ->whereKeyNot($invoice->id)
                ->where('balance', '>', 0)
                ->whereNotIn('status', ['paid', 'cancelled'])
                ->whereDate('due_date', '<', $invoice->due_date)
                ->sum('balance'));
            $invoice->setAttribute('customer_outstanding', (float) Invoice::query()
                ->where('customer_id', $invoice->customer_id)
                ->unpaid()
                ->sum('balance'));
            return $invoice;
        });
        $unremittedPayments = Payment::where('collector_id', $request->user()->id)->whereNull('remittance_id');
        $unremitted = (clone $unremittedPayments)->sum('amount');
        $unremittedCash = (clone $unremittedPayments)->where('payment_method', 'cash')->sum('amount');
        return response()->json(['invoices' => $invoices, 'unremitted_amount' => (float) $unremitted, 'unremitted_cash_amount' => (float) $unremittedCash])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
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
        $invoice = $invoices->generateInvoice($customer, $start, $start->copy()->addMonthNoOverflow()->subDay(), [], now(), now(), null, 'collector_early');
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
            'signature_signer_name' => 'nullable|string|max:120',
        ]);
        $invoice = Invoice::findOrFail($invoiceId);
        abort_unless($invoice->balance > 0 && $invoice->due_date->lte(today()), 422, 'Collectors may record payment only for a due invoice.');
        $customerOutstanding = (float) Invoice::query()
            ->where('customer_id', $invoice->customer_id)
            ->unpaid()
            ->sum('balance');
        $excess = round((float) $data['amount'] - $customerOutstanding, 2);
        abort_if($excess > 0 && $excess <= 1.00, 422, 'An excess of PHP 1.00 or less must be returned as change. Excess above PHP 1.00 is saved as customer advance credit.');
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

    public function startQrPhPayment(string $invoiceId, PaymongoService $paymongo): JsonResponse
    {
        $invoice = Invoice::with('customer')->findOrFail($invoiceId);
        abort_unless($invoice->balance > 0 && $invoice->due_date->lte(today()), 422, 'QR Ph is available for due invoices only.');
        try {
            return response()->json(['message' => 'PayMongo QR Ph payment created.', 'payment' => $paymongo->createQrPhPayment($invoice)]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function attachQrPhPayment(Request $request, string $invoiceId, string $checkoutId, PaymongoService $paymongo): JsonResponse
    {
        $data = $request->validate([
            'payment_method_id' => 'required|string|max:100',
            'qr_image_url' => 'nullable|string|max:2000000',
        ]);
        $checkout = PaymongoCheckout::where('id', $checkoutId)->where('invoice_id', $invoiceId)->firstOrFail();
        try {
            return response()->json(['payment' => $paymongo->finalizeQrPhAttachment($checkout, $data['payment_method_id'], $data['qr_image_url'] ?? null)]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reconcileQrPhPayment(string $invoiceId, string $checkoutId, PaymongoService $paymongo): JsonResponse
    {
        $checkout = PaymongoCheckout::where('id', $checkoutId)->where('invoice_id', $invoiceId)->firstOrFail();
        $paid = $paymongo->reconcileQrPhPayment((string) $checkout->payment_intent_id);
        return response()->json(['paid' => $paid, 'payment_status' => $checkout->fresh()->status]);
    }

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);
        $remittance = DB::transaction(function () use ($request, $data) {
            // Serialize submissions per collector. Locking only matching
            // unremitted payments cannot lock an empty result set, allowing a
            // rapid duplicate HTTP request to race the first transaction.
            User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
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
    private function liquidateExactLegacy(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'cash_breakdown' => 'required|array',
            'cash_breakdown.*.denomination' => 'required|integer|in:1000,500,200,100,50,20,10,5,1',
            'cash_breakdown.*.count' => 'required|integer|min:0|max:100000',
        ]);

        $remittance = DB::transaction(function () use ($request, $id, $data) {
            $remittance = Remittance::with('payments')->lockForUpdate()->findOrFail($id);
            abort_if($remittance->status !== 'submitted', 422, 'This remittance has already been validated.');
            abort_if($remittance->liquidated_at || $remittance->liquidated_by, 422, 'This remittance has already been liquidated.');
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

    public function liquidate(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'cash_breakdown' => 'required|array|size:10',
            'cash_breakdown.*.denomination' => 'required|integer|in:1000,500,200,100,50,20,10,5,1',
            'cash_breakdown.*.kind' => 'required|in:bill,coin',
            'cash_breakdown.*.count' => 'required|integer|min:0|max:100000',
            'cash_return_breakdown' => 'nullable|array|size:10',
            'cash_return_breakdown.*.denomination' => 'required_with:cash_return_breakdown|integer|in:1000,500,200,100,50,20,10,5,1',
            'cash_return_breakdown.*.kind' => 'required_with:cash_return_breakdown|in:bill,coin',
            'cash_return_breakdown.*.count' => 'required_with:cash_return_breakdown|integer|min:0|max:100000',
            'excess_action' => 'nullable|in:return_change,accept_overage',
            'shortage_reason' => 'nullable|in:travel_expense_gas',
            'expense_receipt_reference' => 'nullable|string|max:100',
            'expense_receipt' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);
        $receiptPath = $request->hasFile('expense_receipt')
            ? $request->file('expense_receipt')->store('remittance-expense-receipts', 'local')
            : null;

        try {
            $remittance = DB::transaction(function () use ($request, $id, $data, $receiptPath) {
                $remittance = Remittance::with('payments')->lockForUpdate()->findOrFail($id);
                abort_if($remittance->status !== 'submitted', 422, 'This remittance has already been validated.');
                abort_if($remittance->liquidated_at || $remittance->liquidated_by, 422, 'This remittance has already been liquidated.');
                $breakdown = app(CashDenominationService::class)->normalize($data['cash_breakdown']);
                $returnBreakdown = app(CashDenominationService::class)->normalize($data['cash_return_breakdown'] ?? []);
                $cashCounted = (float) collect($breakdown)->sum('amount');
                $cashReturned = (float) collect($returnBreakdown)->sum('amount');
                $cashExpected = (float) $remittance->payments->where('payment_method', 'cash')->sum('amount');
                abort_if($cashReturned > $cashCounted, 422, 'Returned change cannot be greater than the physical cash received.');
                $cashRetained = round($cashCounted - $cashReturned, 2);
                $variance = round($cashRetained - $cashExpected, 2);
                if ($cashCounted > $cashExpected) {
                    abort_unless(filled($data['excess_action'] ?? null), 422, 'Choose whether excess cash is returned as change or accepted as a cash overage.');
                    if (($data['excess_action'] ?? null) === 'return_change') {
                        abort_unless($cashReturned > 0 && $variance === 0.0, 422, 'Returned-change denominations must make retained cash equal the remittance.');
                    } else {
                        abort_if($cashReturned > 0, 422, 'Do not enter returned denominations when accepting the excess as cash overage.');
                    }
                }

                if ($variance < 0) {
                    abort_unless(($data['shortage_reason'] ?? null) === 'travel_expense_gas', 422, 'Cash below the remittance is allowed only for Travel Expense - Gas.');
                    abort_unless(filled($data['expense_receipt_reference'] ?? null), 422, 'Official gas receipt number/reference is required.');
                }

                $entry = null;
                if ($variance !== 0.0) {
                    $entry = FinancialEntry::create([
                        'type' => $variance > 0 ? 'sale' : 'expense',
                        'category' => $variance > 0 ? 'Remittance Cash Overage' : 'Travel Expenses',
                        'description' => $variance > 0 ? 'Excess cash explicitly accepted during liquidation' : 'Gas expense deducted from collector remittance',
                        'amount' => number_format(abs($variance), 2, '.', ''),
                        'entry_date' => now(config('app.timezone', 'Asia/Manila'))->toDateString(),
                        'payment_method' => $variance > 0 ? 'add_to_cash' : 'cash',
                        'effect_type' => $variance > 0 ? 'cash_in' : 'expense',
                        'source_wallet' => $variance > 0 ? null : 'cash',
                        'destination_wallet' => $variance > 0 ? 'cash' : null,
                        'reference' => $variance > 0 ? 'OVERAGE-'.$remittance->id : trim((string) $data['expense_receipt_reference']),
                        'notes' => $variance > 0 ? 'Explicitly accepted by '.$request->user()->name.' during remittance liquidation.' : ($receiptPath
                                ? 'Official fuel receipt stored privately for remittance '.$remittance->id
                                : 'Gas expense declared during remittance liquidation; no receipt file was uploaded.'),
                        'idempotency_key' => Str::uuid(),
                        'recorded_by' => $request->user()->id,
                    ]);
                }

                $remittance->update([
                    'liquidated_by' => $request->user()->id,
                    'cash_counted_amount' => $cashCounted,
                    'cash_returned_amount' => $cashReturned,
                    'cash_breakdown' => $breakdown,
                    'cash_return_breakdown' => $returnBreakdown,
                    'liquidation_variance' => $variance,
                    'shortage_reason' => $variance < 0 ? 'travel_expense_gas' : null,
                    'expense_receipt_reference' => $variance < 0 ? trim((string) $data['expense_receipt_reference']) : null,
                    'expense_receipt_path' => $variance < 0 ? $receiptPath : null,
                    'variance_financial_entry_id' => $entry?->id,
                    'liquidated_at' => now(),
                ]);
                return $remittance->fresh(['collector', 'liquidator', 'payments']);
            });
        } catch (\Throwable $e) {
            if ($receiptPath) Storage::disk('local')->delete($receiptPath);
            throw $e;
        }

        return response()->json(['message' => $remittance->liquidation_variance > 0
                ? 'Cash liquidation accepted. The explicitly accepted excess was added to Daily Operations.'
                : ($remittance->liquidation_variance < 0
                ? 'Cash liquidation accepted. The documented gas expense was added to Daily Operations.'
                : ((float) $remittance->cash_returned_amount > 0
                    ? 'Cash liquidation accepted. Returned change was recorded and the retained cash matches the remittance.'
                    : 'Cash liquidation matches the collector cash total. You may now validate this remittance.')), 'remittance' => $remittance]);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'date_order' => ['nullable', 'in:newest,oldest'],
            'needs_review' => ['nullable', 'boolean'],
        ]);

        $query = Remittance::query()->whereNull('cancelled_at')->with([
            'collector:id,name,email',
            'liquidator:id,name,email',
            'receiver:id,name,email',
            'payments:id,remittance_id,customer_id,invoice_id,payment_method,amount,payment_number,payment_date,reference',
            'payments.customer:id,account_number,full_name,address',
            'payments.invoice:id,invoice_number',
            'payments.allocations:id,payment_id,invoice_id,amount',
            'payments.allocations.invoice:id,invoice_number',
            'payments.refunds:id,payment_id,amount',
        ]);

        if ($request->boolean('needs_review')) {
            $query->whereIn('status', ['submitted', 'discrepancy']);
        }

        if (! empty($data['month'])) {
            $month = Carbon::createFromFormat('Y-m', $data['month'], config('app.timezone', 'Asia/Manila'));
            $query->whereBetween('submitted_at', [
                $month->copy()->startOfMonth()->utc(),
                $month->copy()->endOfMonth()->utc(),
            ]);
        }

        $query->orderBy('submitted_at', ($data['date_order'] ?? 'newest') === 'oldest' ? 'asc' : 'desc');

        return response()->json($query->paginate(50)->withQueryString());
    }

    public function cancelWrongSubmission(Request $request, string $id): JsonResponse
    {
        $remittance = DB::transaction(function () use ($request, $id) {
            $remittance = Remittance::with('payments.refunds')->lockForUpdate()->findOrFail($id);
            abort_if($remittance->cancelled_at, 422, 'This remittance submission is already cancelled.');
            abort_if($remittance->status !== 'submitted' || $remittance->liquidated_at || $remittance->liquidated_by, 422, 'Only an unliquidated submitted remittance can be cancelled.');
            abort_if($remittance->payments->isEmpty(), 422, 'This remittance has no attached payment audit record.');

            $notFullyRefunded = $remittance->payments->first(fn (Payment $payment) =>
                (int) round($payment->refunds->sum('amount') * 100) < (int) round((float) $payment->amount * 100)
            );
            abort_if($notFullyRefunded, 422, 'This submission still contains money that has not been fully refunded and cannot be removed from liquidation.');

            $remittance->update([
                'cancelled_by' => $request->user()->id,
                'cancelled_at' => now(),
                'cancellation_reason' => 'Wrong submission; every attached customer payment was fully refunded.',
            ]);

            return $remittance;
        });

        return response()->json(['message' => 'Wrong remittance submission removed from the liquidation queue. Its payment and refund audit records were preserved.', 'remittance' => $remittance]);
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['received_amount' => 'required|numeric|min:0', 'notes' => 'nullable|string|max:1000']);
        $remittance = DB::transaction(function () use ($request, $id, $data) {
            $remittance = Remittance::with('payments')->lockForUpdate()->findOrFail($id);
            abort_if($remittance->status !== 'submitted', 422, 'This remittance has already been verified.');
            abort_unless($remittance->liquidated_at && $remittance->liquidated_by, 422, 'Cash must be liquidated by an administrator or cashier before validation.');
            $cashExpected = (float) $remittance->payments->where('payment_method', 'cash')->sum('amount');
            $cashRetained = round((float) $remittance->cash_counted_amount - (float) $remittance->cash_returned_amount, 2);
            $variance = round($cashRetained - $cashExpected, 2);
            abort_unless((int) round($variance * 100) === (int) round((float) $remittance->liquidation_variance * 100), 422, 'The stored cash variance no longer reconciles with recorded payments.');
            $reconciledExpected = round((float) $remittance->declared_amount + (float) $remittance->liquidation_variance, 2);
            $enteredAmount = round((float) $data['received_amount'], 2);
            if ((int) round($enteredAmount * 100) === (int) round((float) $remittance->declared_amount * 100)) {
                $enteredAmount = $reconciledExpected;
            }
            $remittance->update(['received_by' => $request->user()->id, 'received_amount' => $enteredAmount, 'status' => (int) round($enteredAmount * 100) === (int) round($reconciledExpected * 100) ? 'received' : 'discrepancy', 'notes' => trim(($remittance->notes ? $remittance->notes."\n" : '').($data['notes'] ?? '')), 'received_at' => now()]);

            return $remittance->fresh(['collector', 'liquidator', 'receiver', 'payments']);
        });
        return response()->json(['message' => $remittance->status === 'received' ? 'Remittance received and verified.' : 'Remittance recorded with a discrepancy for review.', 'remittance' => $remittance]);
    }
}
