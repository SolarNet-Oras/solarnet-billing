<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Remittance;
use App\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RemittanceController extends Controller
{
    public function collectorDashboard(Request $request): JsonResponse
    {
        $invoices = Invoice::with('customer:id,account_number,full_name,address,contact_number')
            ->where('balance', '>', 0)->whereDate('due_date', '<=', today())
            ->whereIn('status', ['sent', 'overdue', 'partial'])
            ->orderBy('due_date')->paginate($request->integer('per_page', 50));
        $unremitted = Payment::where('collector_id', $request->user()->id)->whereNull('remittance_id')->sum('amount');
        return response()->json(['invoices' => $invoices, 'unremitted_amount' => (float) $unremitted]);
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
