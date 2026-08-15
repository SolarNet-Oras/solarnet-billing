<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Customer;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class PaymentController extends Controller
{
    public function recordAdvance(Request $request, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|uuid|exists:customers,id', 'amount' => 'required|numeric|min:0.01', 'payment_method' => 'required|in:cash', 'payment_date' => 'nullable|date', 'covered_cycle_date' => 'nullable|date', 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:1000',
            'cash_breakdown' => 'required_if:payment_method,cash|array',
            'cash_breakdown.*.denomination' => 'required_with:cash_breakdown|integer|in:1000,500,200,100,50,20,10,5,1',
            'cash_breakdown.*.count' => 'required_with:cash_breakdown|integer|min:0|max:100000',
        ]);
        if ($data['payment_method'] === 'cash') {
            $data['cash_breakdown'] = $this->normalizedCashBreakdown($data['cash_breakdown'] ?? []);
            $data['cash_counted_amount'] = collect($data['cash_breakdown'])->sum('amount');
            if ((int) round((float) $data['cash_counted_amount'] * 100) !== (int) round((float) $data['amount'] * 100)) {
                return response()->json(['message' => 'Cash count must exactly match the advance payment amount.'], 422);
            }
        }
        $customer = Customer::findOrFail($data['customer_id']);
        if (!empty($data['covered_cycle_date']) && !$invoices->isValidFutureBillingCycle(
            $customer,
            Carbon::parse($data['covered_cycle_date'], config('app.timezone', 'Asia/Manila')),
            Carbon::parse($data['payment_date'] ?? now(), config('app.timezone', 'Asia/Manila')),
        )) {
            return response()->json(['message' => 'The selected advance cycle must be a future billing anniversary for this customer.'], 422);
        }
        $data['transaction_id'] = 'ADV-' . now()->format('YmdHis') . '-' . Str::upper(Str::random(6));
        $data['received_by'] = $request->user()->id;
        $payment = $invoices->recordAdvancePayment($customer, $data);
        return response()->json(['message' => 'Advance payment reserved for the selected future billing cycle.', 'payment' => $payment, 'credit_summary' => $invoices->creditSummary($customer)], 201);
    }

    private function normalizedCashBreakdown(array $rows): array
    {
        $counts = collect($rows)->mapWithKeys(fn (array $row) => [(int) $row['denomination'] => (int) $row['count']]);
        return collect([1000, 500, 200, 100, 50, 20, 10, 5, 1])->map(fn (int $denomination) => [
            'denomination' => $denomination,
            'count' => (int) ($counts[$denomination] ?? 0),
            'amount' => $denomination * (int) ($counts[$denomination] ?? 0),
        ])->all();
    }
    /**
     * Get all payments with filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = Payment::with(['invoice', 'customer']);

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by invoice
        if ($request->has('invoice_id')) {
            $query->where('invoice_id', $request->invoice_id);
        }

        // Filter by payment method
        if ($request->has('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('payment_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('payment_date', '<=', $request->to_date);
        }

        $payments = $query->latest('payment_date')
                         ->paginate($request->get('per_page', 15));

        return response()->json($payments);
    }

    /**
     * Get a single payment
     */
    public function show(string $id): JsonResponse
    {
        $payment = Payment::with(['invoice', 'customer'])
                         ->findOrFail($id);

        return response()->json($payment);
    }

    /**
     * Get payment statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $query = Payment::query();

        // Filter by date range if provided
        if ($request->has('from_date')) {
            $query->where('payment_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('payment_date', '<=', $request->to_date);
        }

        $totalPayments = (clone $query)->count();
        $totalAmount = (clone $query)->sum('amount');

        $methodBreakdown = Payment::selectRaw('payment_method, count(*) as count, sum(amount) as total')
            ->when($request->has('from_date'), fn($q) => $q->where('payment_date', '>=', $request->from_date))
            ->when($request->has('to_date'), fn($q) => $q->where('payment_date', '<=', $request->to_date))
            ->groupBy('payment_method')
            ->get();

        return response()->json([
            'total_payments' => $totalPayments,
            'total_amount' => $totalAmount,
            'method_breakdown' => $methodBreakdown,
        ]);
    }
}
