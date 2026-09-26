<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerReferral;
use App\Services\CustomerReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerReferralRewardController extends Controller
{
    public function payCash(Request $request, CustomerReferral $referral, CustomerReferralService $service): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'cash_breakdown' => ['required', 'array', 'size:10'],
            'cash_breakdown.*.denomination' => ['required', 'integer', 'in:1000,500,200,100,50,20,10,5,1'],
            'cash_breakdown.*.kind' => ['required', 'in:bill,coin'],
            'cash_breakdown.*.count' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $referral = $service->payCashReward(
            $referral,
            $request->user(),
            $data['cash_breakdown'],
            trim($data['reference']),
        );

        return response()->json([
            'message' => 'The ₱200 referral reward was released and recorded in Daily Operations.',
            'data' => $referral,
        ]);
    }
}
