<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerTroubleshootingSession;
use App\Services\CustomerPortalTokenService;
use App\Services\CustomerTroubleshootingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerTroubleshootingController extends Controller
{
    public function __construct(
        protected CustomerPortalTokenService $tokens,
        protected CustomerTroubleshootingService $troubleshooting,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $customer = $this->tokens->authenticate($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        return response()->json(['status' => 'success', 'data' => $this->troubleshooting->start($customer)]);
    }

    public function message(Request $request, string $id): JsonResponse
    {
        $customer = $this->tokens->authenticate($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);
        $session = CustomerTroubleshootingSession::where('customer_id', $customer->id)->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $this->troubleshooting->reply($customer, $session, $data['message'])]);
    }

    public function escalate(Request $request, string $id): JsonResponse
    {
        $customer = $this->tokens->authenticate($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthenticated'], 401);
        $session = CustomerTroubleshootingSession::where('customer_id', $customer->id)->findOrFail($id);
        return response()->json(['status' => 'success', 'data' => $this->troubleshooting->createTicket($customer, $session)], 201);
    }
}
