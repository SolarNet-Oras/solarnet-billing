<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\PaymentTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class PaymentTransferController extends Controller
{
    public function store(Request $request, PaymentTransferService $service): JsonResponse
    {
        $data = $request->validate(['payment_id'=>['required','uuid'],'source_invoice_id'=>['required','uuid'],'target_invoice_id'=>['required','uuid'],'amount'=>['required','numeric','gt:0'],'retained_credit'=>['required','numeric','gte:0'],'reason'=>['required','string','max:500'],'idempotency_key'=>['required','string','max:100']]);
        $transfer = $service->transfer(Payment::findOrFail($data['payment_id']), Invoice::findOrFail($data['source_invoice_id']), Invoice::findOrFail($data['target_invoice_id']), (float)$data['amount'], (float)$data['retained_credit'], $data['reason'], $data['idempotency_key']);
        return response()->json(['data'=>$transfer], 201);
    }
}
