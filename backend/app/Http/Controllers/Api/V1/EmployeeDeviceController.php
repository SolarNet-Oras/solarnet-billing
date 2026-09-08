<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmployeeDevice;
use App\Models\EmployeeDeviceAudit;
use App\Models\EmployeeDeviceEnrollment;
use App\Models\EmployeeDeviceCommand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EmployeeDeviceController extends Controller
{
    public function downloadWindowsAgent()
    {
        $path = base_path('resources/agents/SolarNet-Windows-Agent.zip');
        abort_unless(is_file($path), 404, 'Windows agent package is not available in this deployment.');
        return response()->download($path, 'SolarNet-Windows-Agent.zip', ['Content-Type'=>'application/zip','Cache-Control'=>'private, no-store']);
    }

    public function index(): JsonResponse
    {
        $devices = EmployeeDevice::latest('last_seen_at')->get()->map(function ($device) {
            // A one-minute agent heartbeat may occasionally miss a few attempts
            // during Wi-Fi roaming or a backend restart. Avoid false offline flicker.
            $device->online = $device->status === 'active' && $device->last_seen_at?->gt(now()->subMinutes(10));
            return $device;
        });
        $commands = Schema::hasTable('employee_device_commands')
            ? EmployeeDeviceCommand::latest()->limit(50)->get()
            : collect();
        return response()->json(['success'=>true,'data'=>['devices'=>$devices,'audits'=>EmployeeDeviceAudit::latest()->limit(50)->get(),'commands'=>$commands]]);
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
        $data = $request->validate([
            'agent_version'=>'required|string|max:40','os_version'=>'nullable|string|max:120','security_posture'=>'nullable|array',
            'security_posture.firewall_enabled'=>'nullable|boolean','security_posture.defender_enabled'=>'nullable|boolean',
            'security_posture.realtime_protection_enabled'=>'nullable|boolean','security_posture.bitlocker_enabled'=>'nullable|boolean',
            'security_posture.secure_boot_enabled'=>'nullable|boolean','security_posture.pending_reboot'=>'nullable|boolean',
            'security_posture.antivirus_signature_updated_at'=>'nullable|string|max:64',
        ]);
        $update=['agent_version'=>$data['agent_version'],'os_version'=>$data['os_version'] ?? $device->os_version,'last_seen_at'=>now(),'last_ip_hash'=>hash('sha256',(string)$request->ip())];
        if (Schema::hasColumn('employee_devices','security_posture') && isset($data['security_posture'])) {
            // A normal Windows session cannot read every privileged control.
            // Preserve the latest verified value instead of replacing it with null.
            $observed = array_filter($data['security_posture'], fn ($value) => $value !== null);
            $update['security_posture']=array_merge($device->security_posture ?? [], $observed);
            $update['posture_checked_at']=now();
        }
        $device->update($update);
        $commands = Schema::hasTable('employee_device_commands') ? DB::transaction(function () use ($device) {
            $rows = EmployeeDeviceCommand::where('device_id',$device->id)->where('status','queued')->lockForUpdate()->limit(5)->get();
            foreach ($rows as $row) $row->update(['status'=>'delivered','delivered_at'=>now()]);
            return $rows->map(fn ($row) => $row->only(['id','command','message','reason']))->values();
        }) : collect();
        return response()->json(['success'=>true,'data'=>['server_time'=>now()->toIso8601String(),'commands'=>$commands]]);
    }

    public function commandResult(Request $request): JsonResponse
    {
        $token = $request->bearerToken(); abort_unless($token,401,'Device token required.');
        $device = EmployeeDevice::where('token_hash',hash('sha256',$token))->where('status','active')->first(); abort_unless($device,401,'Device token is invalid or revoked.');
        $data=$request->validate(['command_id'=>'required|uuid','status'=>'required|in:accepted,declined,completed,failed','result_message'=>'nullable|string|max:1000']);
        $command=EmployeeDeviceCommand::where('id',$data['command_id'])->where('device_id',$device->id)->where('status','delivered')->firstOrFail();
        $command->update(['status'=>$data['status'],'responded_at'=>now(),'result_message'=>$data['result_message']??null]);
        EmployeeDeviceAudit::create(['device_id'=>$device->id,'event'=>'command_'.$data['status'],'metadata'=>['command_id'=>$command->id,'command'=>$command->command]]);
        return response()->json(['success'=>true]);
    }

    public function requestCommand(Request $request, EmployeeDevice $device): JsonResponse
    {
        abort_if($device->status!=='active',422,'Only an active enrolled device can receive a request.');
        $data=$request->validate(['command'=>'required|in:message,lock,restart','message'=>'nullable|string|max:500','reason'=>'required|string|min:5|max:255','confirmation'=>'required|string|in:REQUEST DEVICE ACTION']);
        if($data['command']==='message' && blank($data['message']??null)) return response()->json(['message'=>'Enter the message to show on the employee device.'],422);
        $command=EmployeeDeviceCommand::create(['device_id'=>$device->id,'requested_by'=>$request->user()->id,'command'=>$data['command'],'message'=>$data['message']??null,'reason'=>$data['reason'],'status'=>'queued']);
        EmployeeDeviceAudit::create(['device_id'=>$device->id,'actor_id'=>$request->user()->id,'event'=>'command_requested','metadata'=>['command_id'=>$command->id,'command'=>$command->command,'reason'=>$command->reason]]);
        return response()->json(['success'=>true,'message'=>'Request queued. The employee device must be online and confirm guarded actions.','data'=>$command],201);
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
