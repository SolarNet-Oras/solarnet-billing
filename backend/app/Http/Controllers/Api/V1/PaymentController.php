<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Customer;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function recordAdvance(Request $request, InvoiceService $invoices): JsonResponse
    {
        $data = $request->validate(['customer_id' => 'required|uuid|exists:customers,id', 'amount' => 'required|numeric|min:0.01', 'payment_method' => 'required|in:cash,bank_transfer,credit_card,debit_card,mobile_money,other', 'payment_date' => 'nullable|date', 'transaction_id' => 'nullable|string|max:255', 'reference' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:1000']);
        $payment = $invoices->recordAdvancePayment(Customer::findOrFail($data['customer_id']), $data);
        return response()->json(['message' => 'Advance payment saved as credit for the next invoice.', 'payment' => $payment], 201);
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
