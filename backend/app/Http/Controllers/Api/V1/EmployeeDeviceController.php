<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDevice;
use App\Models\EmployeeDeviceAudit;
use App\Models\EmployeeDeviceEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmployeeDeviceController extends Controller
{
    public function index(): JsonResponse
    {
        $devices = EmployeeDevice::latest('last_seen_at')->get()->map(function ($device) {
            $device->online = $device->status === 'active' && $device->last_seen_at?->gt(now()->subMinutes(3));
            return $device;
        });
        return response()->json(['success'=>true,'data'=>['devices'=>$devices,'audits'=>EmployeeDeviceAudit::latest()->limit(50)->get()]]);
    }

    public function createEnrollment(Request $request): JsonResponse
    {
        EmployeeDeviceEnrollment::whereNull('used_at')->where('expires_at','<',now())->delete();
        $plain = strtoupper(Str::random(4).'-'.Str::random(4));
        $record = EmployeeDeviceEnrollment::create(['code_hash'=>hash('sha256',$plain),'created_by'=>$request->user()->id,'expires_at'=>now()->addMinutes(10)]);
        EmployeeDeviceAudit::create(['actor_id'=>$request->user()->id,'event'=>'enrollment_code_created','metadata'=>['enrollment_id'=>$record->id,'expires_at'=>$record->expires_at->toIso8601String()]]);
        return response()->json(['success'=>true,'data'=>['code'=>$plain,'expires_at'=>$record->expires_at]]);
    }

    public function enroll(Request $request): JsonResponse
    {
        $data = $request->validate(['code'=>'required|string|max:20','name'=>'required|string|max:120','employee_name'=>'required|string|max:120','platform'=>'required|in:windows,android','os_version'=>'nullable|string|max:120','agent_version'=>'required|string|max:40','device_fingerprint'=>'required|string|min:24|max:500','consent_accepted'=>'accepted','consent_accepted_at'=>'required|date']);
        $enrollment = EmployeeDeviceEnrollment::where('code_hash',hash('sha256',strtoupper($data['code'])))->whereNull('used_at')->where('expires_at','>',now())->first();
        abort_unless($enrollment, 422, 'Enrollment code is invalid or expired.');
        $token = Str::random(80);
        $device = DB::transaction(function () use ($data,$enrollment,$token) {
            $device = EmployeeDevice::create(['enrollment_id'=>$enrollment->id,'name'=>$data['name'],'employee_name'=>$data['employee_name'],'platform'=>$data['platform'],'os_version'=>$data['os_version'] ?? null,'agent_version'=>$data['agent_version'],'device_fingerprint_hash'=>hash('sha256',$data['device_fingerprint']),'token_hash'=>hash('sha256',$token),'consent_accepted'=>true,'consent_accepted_at'=>$data['consent_accepted_at'],'last_seen_at'=>now(),'status'=>'active']);
            $enrollment->update(['used_at'=>now()]);
            EmployeeDeviceAudit::create(['device_id'=>$device->id,'event'=>'device_enrolled','metadata'=>['platform'=>$device->platform,'agent_version'=>$device->agent_version]]);
            return $device;
        });
        return response()->json(['success'=>true,'data'=>['device'=>$device,'device_token'=>$token]],201);
    }

    public function heartbeat(Request $request): JsonResponse
    {
        $token = $request->bearerToken();
        abort_unless($token, 401, 'Device token required.');
        $device = EmployeeDevice::where('token_hash',hash('sha256',$token))->where('status','active')->first();
        abort_unless($device, 401, 'Device token is invalid or revoked.');
        $data = $request->validate(['agent_version'=>'required|string|max:40','os_version'=>'nullable|string|max:120']);
        $device->update(['agent_version'=>$data['agent_version'],'os_version'=>$data['os_version'] ?? $device->os_version,'last_seen_at'=>now(),'last_ip_hash'=>hash('sha256',(string)$request->ip())]);
        return response()->json(['success'=>true,'data'=>['server_time'=>now()->toIso8601String(),'commands'=>[]]]);
    }

    public function revoke(Request $request, EmployeeDevice $device): JsonResponse
    {
        if ($device->status !== 'revoked') {
            $device->update(['status'=>'revoked','revoked_at'=>now(),'revoked_by'=>$request->user()->id,'token_hash'=>hash('sha256',Str::random(80))]);
            EmployeeDeviceAudit::create(['device_id'=>$device->id,'actor_id'=>$request->user()->id,'event'=>'device_revoked']);
        }
        return response()->json(['success'=>true,'message'=>'Device access revoked.']);
    }
}
