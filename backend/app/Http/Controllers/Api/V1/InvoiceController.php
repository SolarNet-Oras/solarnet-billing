<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Services\InvoiceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class InvoiceController extends Controller
{
    protected InvoiceService $invoiceService;

    public function __construct(InvoiceService $invoiceService)
    {
        $this->invoiceService = $invoiceService;
    }

    /**
     * Get all invoices with filters
     */
    public function index(Request $request): JsonResponse
    {
        $query = Invoice::with(['customer', 'items', 'payments']);

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by date range
        if ($request->has('from_date')) {
            $query->where('issue_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('issue_date', '<=', $request->to_date);
        }

        // Filter overdue
        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        // Filter unpaid
        if ($request->boolean('unpaid')) {
            $query->unpaid();
        }

        $invoices = $query->latest('issue_date')
                         ->paginate($request->get('per_page', 15));

        return response()->json($invoices);
    }

    /**
     * Get a single invoice
     */
    public function show(string $id): JsonResponse
    {
        $invoice = Invoice::with(['customer', 'items', 'payments'])
                         ->findOrFail($id);

        return response()->json($invoice);
    }

    /**
     * Generate a new invoice for a customer
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => 'required|uuid|exists:customers,id',
            'billing_period_start' => 'required|date',
            'billing_period_end' => 'required|date|after_or_equal:billing_period_start',
            'due_days' => 'nullable|integer|min:1|max:90',
            'additional_items' => 'nullable|array',
            'additional_items.*.description' => 'required|string',
            'additional_items.*.quantity' => 'nullable|integer|min:1',
            'additional_items.*.unit_price' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customer = Customer::with('servicePlan')->findOrFail($request->customer_id);

        $billingPeriodStart = Carbon::parse($request->billing_period_start);
        $billingPeriodEnd = Carbon::parse($request->billing_period_end);
        $additionalItems = $request->additional_items ?? [];

        // Generate invoice
        $invoice = $this->invoiceService->generateInvoice(
            $customer,
            $billingPeriodStart,
            $billingPeriodEnd,
            $additionalItems
        );

        // Apply discount if provided
        if ($request->has('discount')) {
            $invoice->discount = $request->discount;
            $invoice->save();
            $this->invoiceService->calculateInvoiceTotals($invoice);
        }

        // Set custom due date if provided
        if ($request->has('due_days')) {
            $invoice->due_date = $invoice->issue_date->addDays($request->due_days);
            $invoice->save();
        }

        // Add notes if provided
        if ($request->has('notes')) {
            $invoice->notes = $request->notes;
            $invoice->save();
        }

        return response()->json([
            'message' => 'Invoice generated successfully',
            'invoice' => $invoice->fresh(['customer', 'items', 'payments']),
        ], 201);
    }

    /**
     * Update invoice details (status, notes, etc.)
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:draft,sent,partial,paid,overdue,cancelled',
            'due_date' => 'nullable|date',
            'discount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $invoice->fill($request->only(['status', 'due_date', 'discount', 'notes']));

        if ($request->has('discount')) {
            $invoice->save();
            $this->invoiceService->calculateInvoiceTotals($invoice);
        } else {
            $invoice->save();
        }

        return response()->json([
            'message' => 'Invoice updated successfully',
            'invoice' => $invoice->fresh(['customer', 'items', 'payments']),
        ]);
    }

    /**
     * Delete/Cancel an invoice
     */
    public function destroy(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        // Don't allow deletion of paid invoices
        if ($invoice->status === 'paid') {
            return response()->json([
                'message' => 'Cannot delete paid invoices',
            ], 422);
        }

        // Check if there are payments
        if ($invoice->payments()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete invoices with payment records. Consider cancelling instead.',
            ], 422);
        }

        $invoice->delete();

        return response()->json([
            'message' => 'Invoice deleted successfully',
        ]);
    }

    /**
     * Mark invoice as sent
     */
    public function markAsSent(string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $this->invoiceService->markAsSent($invoice);

        return response()->json([
            'message' => 'Invoice marked as sent',
            'invoice' => $invoice->fresh(['customer', 'items', 'payments']),
        ]);
    }

    /**
     * Record a payment for an invoice
     */
    public function recordPayment(Request $request, string $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,credit_card,debit_card,mobile_money,other',
            'payment_date' => 'nullable|date',
            'transaction_id' => 'nullable|string|max:255',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:1000',
            'cash_breakdown' => 'required_if:payment_method,cash|array',
            'cash_breakdown.*.denomination' => 'required_with:cash_breakdown|integer|in:1000,500,200,100,50,20,10,5,1',
            'cash_breakdown.*.count' => 'required_with:cash_breakdown|integer|min:0|max:100000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Check if amount exceeds balance
        if ($request->amount > $invoice->balance) {
            return response()->json([
                'message' => 'Payment amount exceeds invoice balance',
                'invoice_balance' => $invoice->balance,
            ], 422);
        }

        $paymentData = $request->all();
        if ($request->input('payment_method') === 'cash') {
            $paymentData['cash_breakdown'] = $this->normalizedCashBreakdown($request->input('cash_breakdown', []));
            $paymentData['cash_counted_amount'] = collect($paymentData['cash_breakdown'])->sum('amount');
            if ((int) round((float) $paymentData['cash_counted_amount'] * 100) !== (int) round((float) $request->amount * 100)) {
                return response()->json(['message' => 'Cash count must exactly match the payment amount before it can be recorded.'], 422);
            }
        }
        $paymentData['received_by'] = $request->user()->id;
        $payment = $this->invoiceService->recordPayment($invoice, $paymentData);

        return response()->json([
            'message' => 'Payment recorded successfully',
            'payment' => $payment,
            'invoice' => $invoice->fresh(['customer', 'items', 'payments']),
        ], 201);
    }

    /** Normalize all denominations so every cash payment has an auditable count. */
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
     * Generate and download PDF
     */
    public function downloadPdf(string $id)
    {
        $invoice = Invoice::with(['customer', 'items', 'payments'])
                         ->findOrFail($id);

        $pdf = $this->invoiceService->generatePdf($invoice);

        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }

    /**
     * Generate recurring invoices for all active customers
     */
    public function generateRecurring(Request $request): JsonResponse
    {
        $billingDate = $request->has('billing_date')
            ? Carbon::parse($request->billing_date)
            : now();

        $results = $this->invoiceService->generateRecurringInvoices($billingDate);

        return response()->json([
            'message' => 'Recurring invoices generation completed',
            'results' => $results,
        ]);
    }

    /**
     * Get invoice statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        $query = Invoice::query();

        // Filter by date range if provided
        if ($request->has('from_date')) {
            $query->where('issue_date', '>=', $request->from_date);
        }
        if ($request->has('to_date')) {
            $query->where('issue_date', '<=', $request->to_date);
        }

        $totalInvoices = (clone $query)->count();
        $totalAmount = (clone $query)->sum('total');
        $paidAmount = (clone $query)->where('status', 'paid')->sum('total');
        $unpaidAmount = (clone $query)->whereIn('status', ['sent', 'partial', 'overdue'])->sum('balance');
        $overdueCount = (clone $query)->where('status', 'overdue')->count();
        $overdueAmount = (clone $query)->where('status', 'overdue')->sum('balance');

        $statusBreakdown = Invoice::selectRaw('status, count(*) as count, sum(total) as total')
            ->groupBy('status')
            ->get();

        return response()->json([
            'total_invoices' => $totalInvoices,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'unpaid_amount' => $unpaidAmount,
            'overdue_count' => $overdueCount,
            'overdue_amount' => $overdueAmount,
            'status_breakdown' => $statusBreakdown,
        ]);
    }
}
