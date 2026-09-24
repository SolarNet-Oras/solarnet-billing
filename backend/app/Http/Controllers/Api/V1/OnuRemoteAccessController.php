<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\OnuRemoteSession;
use App\Services\OnuRemoteAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OnuRemoteAccessController extends Controller
{
    public function index(Request $request, OnuRemoteAccessService $service): JsonResponse
    {
        $search=trim($request->string('search')->toString());
        $customers=Customer::query()->with('router:id,name,is_active')->whereNotNull('ip_address')->whereNotNull('router_id')
            ->when($search!=='',fn($q)=>$q->where(fn($nested)=>$nested->where('full_name','ilike',"%{$search}%")->orWhere('account_number','ilike',"%{$search}%")->orWhere('ip_address','ilike',"%{$search}%")))
            ->orderBy('full_name')->limit(100)->get(['id','full_name','account_number','ip_address','mac_address','router_id','onu_information','status']);
        $sessions=OnuRemoteSession::with(['customer:id,full_name,account_number','router:id,name','creator:id,name'])->latest()->limit(30)->get()->map(fn($session)=>[
            ...$session->toArray(),
            'url'=>$session->status==='active' && $session->expires_at->isFuture() ? $service->url($session) : null,
        ]);
        return response()->json(['data'=>[
            'customers'=>$customers,
            'sessions'=>$sessions,
            'session_minutes'=>10,
            'paths'=>['/fh','/adminhtml'],
            'access_ready'=>false,
            'access_blocker'=>'ONU access is disabled until the private MikroTik/VPN management path has been audited and verified.',
        ]]);
    }

    public function store(Request $request, OnuRemoteAccessService $service): JsonResponse
    {
        abort(409, 'Public ONU port forwarding is disabled. Complete the private MikroTik/VPN management-path audit before opening ONU sessions.');

        $data=$request->validate([
            'customer_id'=>['required','uuid','exists:customers,id'],
            'path'=>['required','string','max:200','regex:/^\/[A-Za-z0-9._~!$&\'()*+,;=:@%\/-]*$/'],
            'target_port'=>['required','integer',Rule::in([80,443])],
        ]);
        $customer=Customer::with('router')->findOrFail($data['customer_id']);
        abort_if(OnuRemoteSession::where('customer_id',$customer->id)->where('status','active')->where('expires_at','>',now())->exists(),422,'This ONU already has an active remote session. Close it before opening another.');
        try {
            $session=$service->open($customer,$request->user(),$request->ip(),$data['path'],(int)$data['target_port']);
            return response()->json(['message'=>'Secure ONU session opened for ten minutes.','data'=>[...$session->toArray(),'url'=>$service->url($session)]],201);
        } catch (\Throwable $exception) {
            report($exception);
            return response()->json(['message'=>'Could not open the ONU session. '.$exception->getMessage()],422);
        }
    }

    public function destroy(OnuRemoteSession $onuRemoteSession, OnuRemoteAccessService $service): JsonResponse
    {
        $onuRemoteSession->load('router');
        try { $service->close($onuRemoteSession); }
        catch (\Throwable $exception) { return response()->json(['message'=>'The session was marked for cleanup, but MikroTik could not be reached. '.$exception->getMessage()],502); }
        return response()->json(['message'=>'ONU remote session closed and its owned MikroTik rules were removed.']);
    }
}
