<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TenantCashoutRequest;
use App\Services\TenantCashoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaasTenantCashoutController extends Controller
{
    public function index(Request $request, TenantCashoutService $service): JsonResponse
    {
        $tenant=$request->attributes->get('saas_tenant');
        return response()->json(['data'=>['position'=>$service->position($tenant),'requests'=>TenantCashoutRequest::where('tenant_id',$tenant->id)->latest()->limit(25)->get()]]);
    }
    public function store(Request $request, TenantCashoutService $service): JsonResponse
    {
        $tenant=$request->attributes->get('saas_tenant');$membership=$request->attributes->get('saas_membership');
        abort_unless($membership->is_owner&&$membership->membership_role==='tenant_admin',403,'Only the Tenant Super Administrator can request cash-out.');
        $data=$request->validate(['amount'=>'required|numeric|min:100','gcash_name'=>'required|string|max:255','gcash_number'=>['required','regex:/^09\d{9}$/']]);
        $row=DB::transaction(function()use($request,$service,$tenant,$data):TenantCashoutRequest{
            $tenant->newQuery()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_if((float)$data['amount']>$service->position($tenant)['available_to_request'],422,'The requested amount exceeds the tenant PayMongo virtual balance available for request.');

            return TenantCashoutRequest::create(['tenant_id'=>$tenant->id,'requested_by'=>$request->user('api')->id,'amount'=>round((float)$data['amount'],2),'gcash_name'=>trim($data['gcash_name']),'gcash_number'=>$data['gcash_number'],'status'=>'pending']);
        },3);

        return response()->json([
            'message'=>'Cash-out request submitted for manual platform review. The amount is reserved, but the virtual PayMongo balance is deducted only after the administrator confirms the GCash transfer was sent.',
            'data'=>$row,
            'position'=>$service->position($tenant),
        ],201);
    }
}
