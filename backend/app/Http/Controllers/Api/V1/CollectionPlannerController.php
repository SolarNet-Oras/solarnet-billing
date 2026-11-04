<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CollectionPlan;
use App\Models\CollectionPlannerSetting;
use App\Models\CollectionPlanStop;
use App\Models\CollectionVisit;
use App\Models\CollectorCustomerAssignment;
use App\Models\Customer;
use App\Models\CustomerCollectionPreference;
use App\Models\Payment;
use App\Models\User;
use App\Services\SmartCollectionPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CollectionPlannerController extends Controller
{
    public function dashboard(Request $request, SmartCollectionPlannerService $planner): JsonResponse
    {
        return response()->json($planner->dashboard($request->user()))->header('Cache-Control','no-store');
    }

    public function generate(Request $request, SmartCollectionPlannerService $planner): JsonResponse
    {
        $data=$request->validate([
            'smart_enabled'=>'required|boolean','start_latitude'=>'nullable|numeric|between:-90,90',
            'start_longitude'=>'nullable|numeric|between:-180,180','start_address'=>'nullable|string|max:500',
        ]);
        $plan=$planner->generate($request->user(),(bool)$data['smart_enabled'],$data['start_latitude']??null,$data['start_longitude']??null,$data['start_address']??null);
        return response()->json(['message'=>'Daily collection plan generated. Address-only customers remain included without invented distance estimates.','plan'=>$plan]);
    }

    public function arrive(Request $request, string $stopId, SmartCollectionPlannerService $planner): JsonResponse
    {
        $data=$request->validate([
            'latitude'=>'nullable|numeric|between:-90,90','longitude'=>'nullable|numeric|between:-180,180',
            'accuracy_meters'=>'nullable|numeric|min:0|max:50000','location_exception_reason'=>'nullable|string|min:5|max:500',
            'idempotency_key'=>'required|string|max:100',
        ]);
        $stop=CollectionPlanStop::with(['plan','customer'])->findOrFail($stopId);
        abort_unless($stop->plan->collector_id===$request->user()->id,403);
        $hasGps=isset($data['latitude'],$data['longitude']);
        abort_if(!$hasGps && blank($data['location_exception_reason']??null),422,'GPS coordinates or a documented location exception are required.');
        $distance=$hasGps&&$stop->latitude!==null&&$stop->longitude!==null
            ? $this->distanceMeters((float)$data['latitude'],(float)$data['longitude'],$stop->latitude,$stop->longitude) : null;
        $settings=$planner->settings();
        $verified=$distance!==null&&$distance<=$settings->geofence_meters&&($data['accuracy_meters']??999999)<=$settings->geofence_meters;
        $visit=CollectionVisit::firstOrCreate(['idempotency_key'=>$data['idempotency_key']], [
            'collection_plan_stop_id'=>$stop->id,'collector_id'=>$request->user()->id,'customer_id'=>$stop->customer_id,
            'status'=>'arrived','arrived_at'=>now(),'started_at'=>now(),'latitude'=>$data['latitude']??null,
            'longitude'=>$data['longitude']??null,'accuracy_meters'=>$data['accuracy_meters']??null,
            'distance_from_customer_meters'=>$distance,'gps_verified'=>$verified,
            'requires_review'=>!$verified,'location_exception_reason'=>$data['location_exception_reason']??null,
        ]);
        if($visit->wasRecentlyCreated){
            $stop->update(['status'=>'in_progress']);
            $invoiceRows=$this->collectibleInvoices($stop->customer_id);
            $visit->invoices()->sync($invoiceRows->mapWithKeys(fn($invoice)=>[$invoice->id=>['id'=>(string)\Illuminate\Support\Str::uuid(),'balance_at_visit'=>$invoice->balance]])->all());
        }
        return response()->json(['message'=>$verified?'Arrival GPS verified.':'Arrival recorded for supervisor review.','visit'=>$visit->fresh('invoices')]);
    }

    public function complete(Request $request, string $visitId, SmartCollectionPlannerService $planner): JsonResponse
    {
        $outcomes=['payment_collected','customer_not_home','customer_refused_payment','customer_promised_to_pay','partial_payment_collected','customer_already_paid','incorrect_address','customer_requested_reschedule','unable_to_verify_visit'];
        $data=$request->validate([
            'outcome'=>['required',Rule::in($outcomes)],'remarks'=>'nullable|string|max:2000',
            'photo'=>'nullable|file|mimes:jpg,jpeg,png|max:5120','payment_id'=>'nullable|uuid|exists:payments,id',
        ]);
        $visit=CollectionVisit::with('stop.plan')->findOrFail($visitId);
        abort_unless($visit->collector_id===$request->user()->id,403);
        abort_if($visit->completed_at,422,'This visit is already completed and cannot be overwritten.');
        $failed=!in_array($data['outcome'],['payment_collected','partial_payment_collected','customer_already_paid'],true);
        abort_if($failed&&blank($data['remarks']??null),422,'Remarks are required when payment was not collected.');
        $payment=null;
        if(!empty($data['payment_id'])){
            $payment=Payment::whereKey($data['payment_id'])->where('collector_id',$request->user()->id)->where('customer_id',$visit->customer_id)->firstOrFail();
        }
        if(in_array($data['outcome'],['payment_collected','partial_payment_collected'],true)) abort_unless($payment,422,'Select the recorded payment before completing this visit.');
        $path=$request->hasFile('photo')?$request->file('photo')->store('collection-visits/'.now()->format('Y/m'),'local'):null;
        $visit->update(['status'=>'completed','outcome'=>$data['outcome'],'remarks'=>$data['remarks']??null,'photo_path'=>$path,'payment_id'=>$payment?->id,'completed_at'=>now()]);
        $visit->stop->update(['status'=>'completed','completed_at'=>now()]);
        $updated=$planner->generate($request->user(),$visit->stop->plan->smart_enabled,$visit->stop->plan->start_latitude,$visit->stop->plan->start_longitude,$visit->stop->plan->start_address);
        return response()->json(['message'=>'Visit completed. The remaining plan was recalculated without changing invoice balances.','visit'=>$visit->fresh(),'plan'=>$updated]);
    }

    public function skip(Request $request, string $stopId, SmartCollectionPlannerService $planner): JsonResponse
    {
        $data=$request->validate(['reason'=>'required|string|min:5|max:1000']);
        $stop=CollectionPlanStop::with('plan')->findOrFail($stopId);
        abort_unless($stop->plan->collector_id===$request->user()->id,403);
        abort_if($stop->status==='in_progress',422,'An active visit must be completed, not skipped.');
        $stop->update(['status'=>'skipped','skip_reason'=>$data['reason'],'completed_at'=>now()]);
        return response()->json(['message'=>'Stop skipped with an audit reason. It remains eligible for a future plan.','plan'=>$planner->generate($request->user(),$stop->plan->smart_enabled,$stop->plan->start_latitude,$stop->plan->start_longitude,$stop->plan->start_address)]);
    }

    public function adminIndex(): JsonResponse
    {
        $collectors=User::query()->whereHas('roles',fn($q)=>$q->where('name','collector'))->orderBy('name')->get(['id','name','email','is_active']);
        $assignments=CollectorCustomerAssignment::with(['collector:id,name','customer:id,account_number,full_name,address,gps_coordinates'])->whereNull('unassigned_at')->get();
        $plans=CollectionPlan::with(['collector:id,name','stops.customer:id,account_number,full_name,address','stops.visits'])->whereDate('plan_date',now(config('app.timezone','Asia/Manila')))->get();
        $unassignedQuery=Customer::query()->whereDoesntHave('collectorAssignment')->orderBy('full_name');
        $unassigned=$unassignedQuery->count();
        return response()->json([
            'collectors'=>$collectors,
            'assignments'=>$assignments,
            'plans'=>$plans,
            'unassigned_customers'=>$unassigned,
            'unassigned_customer_records'=>$unassignedQuery->limit(500)->get([
                'id','account_number','full_name','address','gps_coordinates','status',
            ]),
            'settings'=>app(SmartCollectionPlannerService::class)->settings(),
        ]);
    }

    public function assign(Request $request): JsonResponse
    {
        $data=$request->validate(['collector_id'=>'required|uuid|exists:users,id','customer_ids'=>'required|array|min:1','customer_ids.*'=>'uuid|exists:customers,id','territory_label'=>'nullable|string|max:150']);
        abort_unless(User::findOrFail($data['collector_id'])->hasRole('collector'),422,'The selected employee is not a collector.');
        DB::transaction(function()use($data,$request){foreach($data['customer_ids'] as $customerId)CollectorCustomerAssignment::updateOrCreate(['customer_id'=>$customerId],[
            'collector_id'=>$data['collector_id'],'assigned_by'=>$request->user()->id,'territory_label'=>$data['territory_label']??null,'assigned_at'=>now(),'unassigned_at'=>null,
        ]);});
        return response()->json(['message'=>'Customer assignments updated.']);
    }

    public function updatePreference(Request $request,string $customerId): JsonResponse
    {
        $data=$request->validate(['visit_allowed'=>'required|boolean','early_collection_allowed'=>'required|boolean','preferred_start_time'=>'nullable|date_format:H:i','preferred_end_time'=>'nullable|date_format:H:i','address_group_override'=>'nullable|string|max:255','payment_arrangement'=>'nullable|string|max:2000']);
        $preference=CustomerCollectionPreference::updateOrCreate(['customer_id'=>$customerId],[...$data,'updated_by'=>$request->user()->id]);
        return response()->json(['message'=>'Collection preferences updated.','preference'=>$preference]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data=$request->validate(['geofence_meters'=>'required|integer|min:25|max:5000','shift_start'=>'required|date_format:H:i','shift_end'=>'required|date_format:H:i|after:shift_start','break_start'=>'nullable|date_format:H:i','break_end'=>'nullable|date_format:H:i','estimated_visit_minutes'=>'required|integer|min:5|max:180','critical_weight'=>'required|integer|min:1|max:10000','due_today_weight'=>'required|integer|min:1|max:10000','upcoming_three_day_weight'=>'required|integer|min:1|max:10000','upcoming_seven_day_weight'=>'required|integer|min:1|max:10000','repeat_attempt_penalty'=>'required|integer|min:0|max:1000','verified_gps_bonus'=>'required|integer|min:0|max:1000']);
        $settings=app(SmartCollectionPlannerService::class)->settings();$settings->update([...$data,'updated_by'=>$request->user()->id]);
        return response()->json(['message'=>'Planner rules updated.','settings'=>$settings->fresh()]);
    }

    public function visitPhoto(Request $request,string $visitId)
    {
        $visit=CollectionVisit::findOrFail($visitId);
        abort_unless($request->user()->id===$visit->collector_id||$request->user()->hasAnyRole(['super_admin','admin','office_admin']),403);
        abort_unless($visit->photo_path&&Storage::disk('local')->exists($visit->photo_path),404);
        return Storage::disk('local')->download($visit->photo_path);
    }

    private function collectibleInvoices(string $customerId)
    { return \App\Models\Invoice::where('customer_id',$customerId)->where('balance','>',0)->whereIn('status',['sent','partial','overdue'])->get(); }

    private function distanceMeters(float $aLat,float $aLng,float $bLat,float $bLng): float
    { $earth=6371000;$dLat=deg2rad($bLat-$aLat);$dLng=deg2rad($bLng-$aLng);$a=sin($dLat/2)**2+cos(deg2rad($aLat))*cos(deg2rad($bLat))*sin($dLng/2)**2;return round($earth*2*atan2(sqrt($a),sqrt(1-$a)),2); }
}
