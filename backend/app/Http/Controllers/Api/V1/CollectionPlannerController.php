<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CollectionPlan;
use App\Models\CollectionPlannerSetting;
use App\Models\CollectionPlanStop;
use App\Models\CollectionVisit;
use App\Models\CollectionLocationDeferral;
use App\Models\CollectionRegistrationEvidence;
use App\Models\CollectorTerritory;
use App\Models\CollectionPlannerRun;
use App\Models\Setting;
use App\Models\CollectorCustomerAssignment;
use App\Models\Customer;
use App\Models\CustomerCollectionPreference;
use App\Models\Payment;
use App\Models\User;
use App\Services\SmartCollectionPlannerService;
use App\Services\CollectionAssignmentRoutingService;
use App\Services\CollectionEvidenceRetentionService;
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

    public function complete(
        Request $request,
        string $visitId,
        SmartCollectionPlannerService $planner,
        CollectionAssignmentRoutingService $routing,
    ): JsonResponse
    {
        $outcomes=['payment_collected','customer_not_home','customer_refused_payment','customer_promised_to_pay','partial_payment_collected','customer_already_paid','incorrect_address','customer_requested_reschedule','unable_to_verify_visit'];
        $data=$request->validate([
            'outcome'=>['required',Rule::in($outcomes)],'remarks'=>'nullable|string|max:2000',
            'photo'=>'nullable|file|mimes:jpg,jpeg,png|max:12288','payment_id'=>'nullable|uuid|exists:payments,id',
        ]);
        $visit=CollectionVisit::with('stop.plan')->find($visitId);
        if (!$visit) {
            $stop=CollectionPlanStop::with('plan')->findOrFail($visitId);
            abort_unless($stop->plan->collector_id===$request->user()->id,403);
            abort_unless($stop->status==='in_progress',422,'This collection stop is not currently in progress.');
            $visit=CollectionVisit::with('stop.plan')
                ->where('collection_plan_stop_id',$stop->id)
                ->where('collector_id',$request->user()->id)
                ->where('status','arrived')
                ->whereNull('completed_at')
                ->latest('arrived_at')
                ->first();
            if (!$visit) {
                $visit=CollectionVisit::create([
                    'collection_plan_stop_id'=>$stop->id,
                    'collector_id'=>$request->user()->id,
                    'customer_id'=>$stop->customer_id,
                    'status'=>'arrived',
                    'arrived_at'=>now(),
                    'started_at'=>now(),
                    'requires_review'=>true,
                    'location_exception_reason'=>'Recovered from an in-progress collection stop during proof-backed completion.',
                    'idempotency_key'=>'recovered-'.$stop->id,
                ])->load('stop.plan');
            }
        }
        abort_unless($visit->collector_id===$request->user()->id,403);
        abort_if($visit->completed_at,422,'This visit is already completed and cannot be overwritten.');
        $failed=!in_array($data['outcome'],['payment_collected','partial_payment_collected','customer_already_paid'],true);
        abort_if($failed&&blank($data['remarks']??null),422,'Remarks are required when payment was not collected.');
        $payment=null;
        if(!empty($data['payment_id'])){
            $payment=Payment::whereKey($data['payment_id'])->where('collector_id',$request->user()->id)->where('customer_id',$visit->customer_id)->firstOrFail();
        }
        if(in_array($data['outcome'],['payment_collected','partial_payment_collected'],true)) abort_unless($payment,422,'Select the recorded payment before completing this visit.');
        $withoutCollectedPayment=!in_array($data['outcome'],['payment_collected','partial_payment_collected'],true);
        abort_if($withoutCollectedPayment&&!$request->hasFile('photo'),422,'A clear proof-of-visit photo is required when completing an attempt without receiving payment.');
        abort_if(!$visit->gps_verified && !$request->hasFile('photo'),422,'A visit photo is required when arrival GPS could not be verified.');
        $path=$request->hasFile('photo')?$request->file('photo')->store('collection-visits/'.now()->format('Y/m'),'local'):null;
        $visit->update(['status'=>'completed','outcome'=>$data['outcome'],'remarks'=>$data['remarks']??null,'photo_path'=>$path,'payment_id'=>$payment?->id,'completed_at'=>now()]);
        $visit->stop->update(['status'=>'completed','completed_at'=>now()]);
        $routingWarning = null;
        if ($failed) {
            try {
                $routing->apply($routing->preview()->where('customer_id', $visit->customer_id));
            } catch (\Throwable $exception) {
                report($exception);
                $routingWarning = 'The visit was saved, but follow-up assignment requires administrator review.';
            }
        }
        $updated=$planner->generate($request->user(),$visit->stop->plan->smart_enabled,$visit->stop->plan->start_latitude,$visit->stop->plan->start_longitude,$visit->stop->plan->start_address);
        return response()->json(['message'=>'Visit completed. The remaining plan was recalculated without changing invoice balances.',
            'routing_warning'=>$routingWarning,'visit'=>$visit->fresh(),'plan'=>$updated]);
    }

    public function skip(Request $request, string $stopId, SmartCollectionPlannerService $planner): JsonResponse
    {
        $data=$request->validate(['reason'=>'required|string|min:5|max:1000','photo'=>'required|file|mimes:jpg,jpeg,png|max:12288','idempotency_key'=>'required|string|max:100']);
        $stop=CollectionPlanStop::with('plan')->findOrFail($stopId);
        abort_unless($stop->plan->collector_id===$request->user()->id,403);
        abort_if($stop->status==='in_progress',422,'An active visit must be completed, not skipped.');
        $path=$request->file('photo')->store('collection-visits/'.now()->format('Y/m'),'local');
        DB::transaction(function()use($stop,$request,$data,$path){
            $stop->update(['status'=>'skipped','skip_reason'=>$data['reason'],'completed_at'=>now()]);
            CollectionVisit::firstOrCreate(['idempotency_key'=>$data['idempotency_key']],[
                'collection_plan_stop_id'=>$stop->id,'collector_id'=>$request->user()->id,'customer_id'=>$stop->customer_id,
                'status'=>'completed','outcome'=>'stop_skipped','remarks'=>$data['reason'],'photo_path'=>$path,
                'requires_review'=>true,'completed_at'=>now(),
            ]);
        });
        return response()->json(['message'=>'Stop skipped with an audit reason. It remains eligible for a future plan.','plan'=>$planner->generate($request->user(),$stop->plan->smart_enabled,$stop->plan->start_latitude,$stop->plan->start_longitude,$stop->plan->start_address)]);
    }

    public function deferLocationUpdate(Request $request, string $stopId): JsonResponse
    {
        $data=$request->validate(['reason'=>'required|string|min:10|max:1000']);
        $stop=CollectionPlanStop::with(['plan','customer'])->findOrFail($stopId);
        abort_unless($stop->plan->collector_id===$request->user()->id,403);
        abort_unless(blank($stop->customer->gps_coordinates),422,'This customer already has registered coordinates.');
        $deferral=CollectionLocationDeferral::create([
            'customer_id'=>$stop->customer_id,'collector_id'=>$request->user()->id,
            'collection_plan_stop_id'=>$stop->id,'context'=>'collection_away_from_service_address',
            'reason'=>$data['reason'],'deferred_at'=>now(),
        ]);
        return response()->json([
            'message'=>'Coordinate update deferred and recorded. Payment may continue, but update the location during a future visit to the actual service address.',
            'deferral'=>$deferral,
        ],201);
    }

    public function captureRegistrationEvidence(Request $request, string $stopId): JsonResponse
    {
        $data=$request->validate([
            'router_label_photo'=>'required|file|mimes:jpg,jpeg,png|max:12288',
            'email'=>'nullable|email:rfc|max:255',
            'contact_number'=>'nullable|string|min:7|max:30',
        ]);
        $stop=CollectionPlanStop::with(['plan','customer'])->findOrFail($stopId);
        abort_unless($stop->plan->collector_id===$request->user()->id,403);
        abort_unless(in_array($stop->status,['planned','in_progress'],true),422,'Registration evidence is allowed only for an active collection stop.');
        $path=$request->file('router_label_photo')->store('collection-registration/'.now()->format('Y/m'),'local');
        $evidence=DB::transaction(function()use($data,$stop,$request,$path){
            $updates=[];
            if(blank($stop->customer->email)&&filled($data['email']??null))$updates['email']=mb_strtolower(trim($data['email']));
            if(blank($stop->customer->contact_number)&&filled($data['contact_number']??null))$updates['contact_number']=trim($data['contact_number']);
            if($updates)$stop->customer->update($updates);
            return CollectionRegistrationEvidence::create([
                'customer_id'=>$stop->customer_id,'collection_plan_stop_id'=>$stop->id,
                'collector_id'=>$request->user()->id,'router_label_photo_path'=>$path,
                'submitted_email'=>$data['email']??null,'submitted_phone'=>$data['contact_number']??null,'status'=>'pending',
            ]);
        });
        return response()->json(['message'=>'Router-label evidence saved for office review. Contact details were filled only where previously missing.','evidence'=>$evidence],201);
    }

    public function registrationEvidencePhoto(Request $request,string $evidenceId)
    {
        $evidence=CollectionRegistrationEvidence::findOrFail($evidenceId);
        abort_unless($request->user()->id===$evidence->collector_id||$request->user()->hasAnyRole(['super_admin','admin','office_admin']),403);
        abort_unless(filled($evidence->router_label_photo_path)&&Storage::disk('local')->exists($evidence->router_label_photo_path),404);
        return Storage::disk('local')->download($evidence->router_label_photo_path);
    }

    public function reviewRegistrationEvidence(Request $request,string $evidenceId,CollectionEvidenceRetentionService $retention): JsonResponse
    {
        $data=$request->validate(['mac_address'=>['required','string','regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],'review_notes'=>'nullable|string|max:1000']);
        $evidence=CollectionRegistrationEvidence::with('customer')->where('status','pending')->findOrFail($evidenceId);
        $mac=strtoupper(str_replace('-',':',$data['mac_address']));
        $hex=str_replace(':','',$mac);
        abort_if(Customer::query()->where('id','!=',$evidence->customer_id)->whereNotNull('mac_address')
            ->whereRaw("upper(replace(replace(mac_address, ':', ''), '-', '')) = ?",[$hex])->exists(),422,'This MAC address already belongs to another customer.');
        DB::transaction(function()use($evidence,$request,$data,$mac){
            $evidence->customer->update(['mac_address'=>$mac,'mac_binding_status'=>'pending']);
            $evidence->update(['status'=>'approved','verified_mac_address'=>$mac,'reviewed_by'=>$request->user()->id,
                'reviewed_at'=>now(),'review_notes'=>$data['review_notes']??null]);
        });
        $retention->purgeRouterLabelPhoto($evidence->fresh());
        return response()->json(['message'=>'MAC address verified and saved. Network binding remains subject to the existing safe synchronization process.','evidence'=>$evidence->fresh()]);
    }

    public function adminIndex(): JsonResponse
    {
        $collectors=User::query()->eligibleFieldCollector()->orderBy('name')->get(['id','name','email','is_active']);
        $assignments=CollectorCustomerAssignment::with(['collector:id,name','customer:id,account_number,full_name,address,gps_coordinates'])->whereNull('unassigned_at')->get();
        $plans=CollectionPlan::with(['collector:id,name','stops.customer:id,account_number,full_name,address','stops.visits'])->whereDate('plan_date',now(config('app.timezone','Asia/Manila')))->get();
        $todayStops=$plans->flatMap->stops;
        $latestTodayRun=CollectionPlannerRun::query()->whereDate('run_date',now(config('app.timezone','Asia/Manila')))
            ->where('dry_run',false)->latest('started_at')->first();
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
            'customers_missing_coordinates'=>Customer::query()->whereHas('collectorAssignment')
                ->whereNull('gps_coordinates')->count(),
            'location_deferrals_today'=>CollectionLocationDeferral::with(['collector:id,name','customer:id,full_name,account_number,address'])
                ->whereDate('deferred_at',now(config('app.timezone','Asia/Manila')))->latest('deferred_at')->get(),
            'registration_evidence'=>CollectionRegistrationEvidence::with(['collector:id,name','customer:id,full_name,account_number,email,contact_number,mac_address'])
                ->latest()->limit(200)->get(),
            'territories'=>CollectorTerritory::with('collector:id,name')->orderByDesc('priority')->orderBy('name')->get(),
            'planner_runs'=>CollectionPlannerRun::latest('started_at')->limit(30)->get(),
            'coverage_report'=>[
                'scheduled'=>$todayStops->whereIn('status',['planned','in_progress'])->count(),
                'unscheduled'=>$todayStops->where('status','unscheduled')->count()+(int)($latestTodayRun?->unassigned_exceptions??0),
                'completed'=>$todayStops->where('status','completed')->count(),
                'failed'=>$todayStops->filter(fn($stop)=>$stop->visits->contains(fn($visit)=>$visit->completed_at
                    && !in_array($visit->outcome,['payment_collected','partial_payment_collected','customer_already_paid'],true)))->count(),
                'total_collectible'=>(float)$todayStops->sum('collectible_balance'),
            ],
            'automation'=>[
                'environment_enabled'=>(bool)config('collection_planner.automation_enabled',false),
                'application_enabled'=>(bool)Setting::get('collection_planner.auto_enabled',false),
                'active'=>(bool)config('collection_planner.automation_enabled',false) && (bool)Setting::get('collection_planner.auto_enabled',false),
                'daily_at'=>config('collection_planner.daily_at','07:15'),
                'timezone'=>config('collection_planner.timezone','Asia/Manila'),
                'algorithm_version'=>config('collection_planner.algorithm_version'),
            ],
            'settings'=>app(SmartCollectionPlannerService::class)->settings(),
        ]);
    }

    public function storeTerritory(Request $request): JsonResponse
    {
        return $this->saveTerritory($request,new CollectorTerritory(),201);
    }

    public function updateTerritory(Request $request,string $territoryId): JsonResponse
    {
        return $this->saveTerritory($request,CollectorTerritory::findOrFail($territoryId));
    }

    public function assign(Request $request): JsonResponse
    {
        $data=$request->validate(['collector_id'=>'required|uuid|exists:users,id','customer_ids'=>'required|array|min:1','customer_ids.*'=>'uuid|exists:customers,id','territory_label'=>'nullable|string|max:150']);
        abort_unless(User::findOrFail($data['collector_id'])->isEligibleFieldCollector(),422,'The selected employee is not an eligible field collector.');
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

    private function saveTerritory(Request $request,CollectorTerritory $territory,int $status=200): JsonResponse
    {
        $data=$request->validate([
            'collector_id'=>'required|uuid|exists:users,id','name'=>'required|string|max:150',
            'address_pattern'=>'nullable|string|max:255','is_fallback'=>'required|boolean','is_active'=>'required|boolean',
            'priority'=>'required|integer|min:0|max:10000','max_daily_visits'=>'required|integer|min:1|max:200',
            'start_latitude'=>'nullable|numeric|between:-90,90|required_with:start_longitude',
            'start_longitude'=>'nullable|numeric|between:-180,180|required_with:start_latitude',
            'start_address'=>'nullable|string|max:500',
        ]);
        $collector=User::findOrFail($data['collector_id']);
        abort_unless($collector->isEligibleFieldCollector(),422,'Territories may be assigned only to eligible field collectors.');
        abort_if(!$data['is_fallback']&&blank($data['address_pattern']),422,'A non-fallback territory requires an address pattern.');
        if(!$territory->exists)$data['created_by']=$request->user()->id;
        $territory->fill($data)->save();
        return response()->json(['message'=>'Collector territory saved.','territory'=>$territory->fresh('collector:id,name')],$status);
    }
}
