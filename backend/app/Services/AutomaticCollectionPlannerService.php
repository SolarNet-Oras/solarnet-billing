<?php

namespace App\Services;

use App\Models\CollectionPlannerRun;
use App\Models\CollectorCustomerAssignment;
use App\Models\CollectorTerritory;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AutomaticCollectionPlannerService
{
    public function __construct(private readonly SmartCollectionPlannerService $planner) {}

    public function eligibleCustomerIds(Carbon $date): Collection
    {
        $today=$date->copy()->startOfDay();
        $through=$today->copy()->addDays(7);
        return Invoice::query()->where('balance','>',0)->whereIn('status',['sent','partial','overdue'])
            ->whereDate('due_date','<=',$through)
            ->whereHas('customer',fn($q)=>$q->whereIn('status',['active','suspended'])
                ->where(fn($q)=>$q->whereDoesntHave('collectionPreference')->orWhereHas('collectionPreference',fn($p)=>$p->where('visit_allowed',true))))
            ->where(fn($q)=>$q->whereDate('due_date','<=',$today)
                ->orWhereHas('customer.collectionPreference',fn($p)=>$p->where('early_collection_allowed',true)))
            ->distinct()->pluck('customer_id')->values();
    }

    public function previewAssignments(Collection $customerIds): array
    {
        $territories=CollectorTerritory::query()->with('collector.roles')->where('is_active',true)
            ->orderByDesc('priority')->get()->filter(fn($t)=>$t->collector?->is_active && $t->collector->hasRole('collector'));
        $existing=CollectorCustomerAssignment::query()->with('collector.roles')->whereIn('customer_id',$customerIds)
            ->whereNull('unassigned_at')->get()->keyBy('customer_id');
        $customers=\App\Models\Customer::whereIn('id',$customerIds)->get(['id','full_name','account_number','address']);
        $loads=$existing->filter(fn($a)=>$a->collector?->is_active && $a->collector->hasRole('collector'))->countBy('collector_id');
        $proposals=[];$exceptions=[];
        foreach($customers as $customer){
            $assignment=$existing->get($customer->id);
            if($assignment?->collector?->is_active && $assignment->collector->hasRole('collector')){
                $proposals[]=['customer'=>$customer,'collector'=>$assignment->collector,'territory'=>null,'existing'=>true];continue;
            }
            $address=mb_strtolower(trim((string)$customer->address));
            $matches=$territories->filter(fn($t)=>!$t->is_fallback && filled($t->address_pattern)
                && str_contains($address,mb_strtolower(trim($t->address_pattern))));
            if($matches->isEmpty())$matches=$territories->where('is_fallback',true);
            $territory=$matches->sort(function($a,$b)use($loads){
                return ((int)$b->priority <=> (int)$a->priority)
                    ?: ((int)($loads[$a->collector_id]??0) <=> (int)($loads[$b->collector_id]??0))
                    ?: strcmp($a->collector_id,$b->collector_id);
            })->first();
            if(!$territory){$exceptions[]=['customer_id'=>$customer->id,'customer'=>$customer->full_name,'account_number'=>$customer->account_number,'address'=>$customer->address,'reason'=>'no_authorized_territory'];continue;}
            $currentLoad=(int)($loads[$territory->collector_id]??0);
            if($currentLoad >= $territory->max_daily_visits){$exceptions[]=['customer_id'=>$customer->id,'customer'=>$customer->full_name,'account_number'=>$customer->account_number,'address'=>$customer->address,'reason'=>'territory_capacity_reached'];continue;}
            $loads[$territory->collector_id]=$currentLoad+1;
            $proposals[]=['customer'=>$customer,'collector'=>$territory->collector,'territory'=>$territory,'existing'=>false];
        }
        return ['proposals'=>$proposals,'exceptions'=>$exceptions];
    }

    public function run(Carbon $date, bool $dryRun, string $trigger): CollectionPlannerRun
    {
        $version=(string)config('collection_planner.algorithm_version','phase1-v1');
        $key=hash('sha256',implode('|',['daily',$date->toDateString(),$version,$dryRun?'dry':'execute']));
        $run=CollectionPlannerRun::firstOrCreate(['idempotency_key'=>$key],[
            'run_date'=>$date,'trigger'=>$trigger,'status'=>'running','algorithm_version'=>$version,
            'dry_run'=>$dryRun,'started_at'=>now(),
        ]);
        if(!$run->wasRecentlyCreated && $run->status==='success')return $run;
        $run->update(['status'=>'running','failure_reason'=>null,'started_at'=>now(),'finished_at'=>null,'trigger'=>$trigger]);
        try{
            $eligible=$this->eligibleCustomerIds($date);
            $assignmentPlan=$this->previewAssignments($eligible);
            $newAssignments=collect($assignmentPlan['proposals'])->where('existing',false);
            $plans=[];$errors=[];
            if(!$dryRun){
                DB::transaction(function()use($newAssignments){foreach($newAssignments as $proposal){CollectorCustomerAssignment::updateOrCreate(
                    ['customer_id'=>$proposal['customer']->id],
                    ['collector_id'=>$proposal['collector']->id,'territory_label'=>$proposal['territory']?->name,'assigned_at'=>now(),'unassigned_at'=>null]
                );}});
                $collectorIds=collect($assignmentPlan['proposals'])->pluck('collector.id')->unique();
                foreach(User::whereIn('id',$collectorIds)->get() as $collector){
                    try{
                        $territory=CollectorTerritory::where('collector_id',$collector->id)->where('is_active',true)->orderByDesc('priority')->first();
                        $plans[]=$this->planner->generate($collector,true,$territory?->start_latitude,$territory?->start_longitude,$territory?->start_address,$run->id,'automatic',$date);
                    }catch(\Throwable $e){$errors[]=['collector_id'=>$collector->id,'collector'=>$collector->name,'error'=>$e->getMessage()];}
                }
            }
            $scheduled=collect($plans)->sum(fn($p)=>$p->stops->where('status','planned')->count());
            $unscheduled=collect($plans)->sum(fn($p)=>$p->stops->where('status','unscheduled')->count());
            $run->update([
                'status'=>$errors?'partial':'success','eligible_customers'=>$eligible->count(),
                'new_assignments'=>$newAssignments->count(),'unassigned_exceptions'=>count($assignmentPlan['exceptions']),
                'collectors_processed'=>collect($assignmentPlan['proposals'])->pluck('collector.id')->unique()->count(),
                'plans_generated'=>count($plans),'scheduled_visits'=>$scheduled,'unscheduled_visits'=>$unscheduled,
                'summary'=>['assignment_exceptions'=>$assignmentPlan['exceptions'],'errors'=>$errors,'dry_run'=>$dryRun],
                'failure_reason'=>$errors?count($errors).' collector plan(s) failed':null,'finished_at'=>now(),
            ]);
        }catch(\Throwable $e){$run->update(['status'=>'error','failure_reason'=>$e->getMessage(),'summary'=>['exception'=>get_class($e)],'finished_at'=>now()]);}
        return $run->fresh('plans.stops');
    }
}
