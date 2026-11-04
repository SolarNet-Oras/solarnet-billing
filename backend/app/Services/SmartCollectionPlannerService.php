<?php

namespace App\Services;

use App\Models\CollectionPlan;
use App\Models\CollectionPlannerSetting;
use App\Models\CollectionPlanStop;
use App\Models\CollectionVisit;
use App\Models\CollectionLocationDeferral;
use App\Models\CollectorCustomerAssignment;
use App\Models\CustomerCollectionPreference;
use App\Models\CustomerLocationEvent;
use App\Models\DhcpLease;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmartCollectionPlannerService
{
    public function settings(): CollectionPlannerSetting
    {
        return CollectionPlannerSetting::firstOrCreate([], [
            'geofence_meters'=>100, 'shift_start'=>'08:00', 'shift_end'=>'17:00',
            'estimated_visit_minutes'=>20, 'critical_weight'=>400, 'due_today_weight'=>300,
            'upcoming_three_day_weight'=>200, 'upcoming_seven_day_weight'=>100,
            'repeat_attempt_penalty'=>25, 'verified_gps_bonus'=>10,
        ]);
    }

    public function eligibleCustomers(User $collector, ?Carbon $at = null): Collection
    {
        $today = ($at ?: now(config('app.timezone', 'Asia/Manila')))->copy()->startOfDay();
        $through = $today->copy()->addDays(7);
        $assignedIds = CollectorCustomerAssignment::query()
            ->where('collector_id', $collector->id)->whereNull('unassigned_at')->pluck('customer_id');
        if ($assignedIds->isEmpty()) return collect();

        $latestLocationEvents = CustomerLocationEvent::query()
            ->whereIn('customer_id', $assignedIds)
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->latest('created_at')->get()->unique('customer_id')->keyBy('customer_id');
        $liveLeases = DhcpLease::query()
            ->whereIn('customer_id', $assignedIds)->presentOnRouter()->where('status', 'bound')
            ->with('router:id,name')->latest('last_seen_at')->get()->unique('customer_id')->keyBy('customer_id');

        $invoices = Invoice::query()
            ->with(['customer:id,account_number,full_name,address,contact_number,gps_coordinates,location_status', 'customer.collectionPreference'])
            ->whereIn('customer_id', $assignedIds)
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereHas('customer', fn ($query) => $query->whereIn('status', ['active', 'suspended']))
            ->whereDate('due_date', '<=', $through)
            ->orderBy('due_date')
            ->get();

        $settings = $this->settings();
        return $invoices->groupBy('customer_id')->map(function (Collection $customerInvoices) use ($collector, $today, $settings, $latestLocationEvents, $liveLeases) {
            /** @var Invoice $first */
            $first = $customerInvoices->first();
            $customer = $first->customer;
            $preference = $customer?->collectionPreference;
            if (!$customer || ($preference && !$preference->visit_allowed)) return null;

            $dueInvoices = $customerInvoices->filter(fn (Invoice $invoice) => $invoice->due_date->copy()->startOfDay()->lte($today));
            $upcomingInvoices = $customerInvoices->filter(fn (Invoice $invoice) => $invoice->due_date->copy()->startOfDay()->gt($today));
            $earliest = $customerInvoices->min(fn (Invoice $invoice) => $invoice->due_date->toDateString());
            $daysUntilDue = $today->diffInDays(Carbon::parse($earliest), false);
            [$priority, $weight] = match (true) {
                $daysUntilDue < 0 => ['critical', $settings->critical_weight],
                $daysUntilDue === 0 => ['high', $settings->due_today_weight],
                $daysUntilDue <= 3 => ['medium', $settings->upcoming_three_day_weight],
                default => ['low', $settings->upcoming_seven_day_weight],
            };
            $attempts = CollectionVisit::query()
                ->where('customer_id', $customer->id)->whereNotNull('completed_at')
                ->whereNotIn('outcome', ['payment_collected', 'partial_payment_collected', 'customer_already_paid'])
                ->count();
            $coordinates = $this->coordinates($customer->gps_coordinates);
            $locationSource = $coordinates ? 'verified_gps' : null;
            $locationEvent = $latestLocationEvents->get($customer->id);
            if (!$coordinates && $locationEvent) {
                $coordinates = $this->coordinates(['latitude'=>$locationEvent->latitude,'longitude'=>$locationEvent->longitude]);
                if ($coordinates) $locationSource = 'operations_map_saved_location';
            }
            $liveLease = $liveLeases->get($customer->id);
            $locationSource ??= $liveLease ? 'dhcp_live_address' : 'address_only';
            $score = $weight - ($attempts * $settings->repeat_attempt_penalty)
                + ($coordinates ? $settings->verified_gps_bonus : 0);

            return [
                'customer'=>$customer, 'invoices'=>$customerInvoices->values(),
                'collectible_balance'=>(float)$customerInvoices->sum('balance'),
                'earliest_due_date'=>$earliest, 'days_until_due'=>$daysUntilDue,
                'due_balance'=>(float)$dueInvoices->sum('balance'),
                'upcoming_balance'=>(float)$upcomingInvoices->sum('balance'),
                'priority_level'=>$priority, 'priority_score'=>$score,
                'attempt_count'=>$attempts, 'coordinates'=>$coordinates,
                'location_source'=>$locationSource,
                'dhcp_live'=>$liveLease !== null, 'dhcp_ip'=>$liveLease?->ip_address,
                'dhcp_router'=>$liveLease?->router?->name,
                'dhcp_last_seen_at'=>$liveLease?->last_seen_at?->toIso8601String(),
                'address_group'=>$preference?->address_group_override ?: $this->addressGroup((string)$customer->address),
                'early_collection_allowed'=>(bool)($preference?->early_collection_allowed ?? false),
                'preferred_start_time'=>$preference?->preferred_start_time,
                'preferred_end_time'=>$preference?->preferred_end_time,
                'payment_arrangement'=>$preference?->payment_arrangement,
                'location_update_deferred_today'=>CollectionLocationDeferral::query()
                    ->where('collector_id',$collector->id)->where('customer_id',$customer->id)
                    ->whereDate('deferred_at',$today)->exists(),
                'plan_eligible'=>$dueInvoices->isNotEmpty() || (bool)($preference?->early_collection_allowed ?? false),
            ];
        })->filter()->values();
    }

    public function dashboard(User $collector): array
    {
        $today = now(config('app.timezone', 'Asia/Manila'))->startOfDay();
        $rows = $this->eligibleCustomers($collector, $today);
        $dueToday = $rows->filter(fn ($row) => $row['days_until_due'] === 0);
        $overdue = $rows->filter(fn ($row) => $row['days_until_due'] < 0);
        $upcoming = $rows->filter(fn ($row) => $row['days_until_due'] > 0 && $row['days_until_due'] <= 7);
        $plan = CollectionPlan::with(['stops.customer:id,account_number,full_name,address,contact_number,gps_coordinates'])
            ->where('collector_id', $collector->id)->whereDate('plan_date', $today)->first();
        $collected = Payment::query()->where('collector_id', $collector->id)->whereDate('payment_date', $today)->sum('amount');

        return [
            'summary'=>[
                'due_today_customers'=>$dueToday->count(), 'due_today_amount'=>(float)$dueToday->sum('due_balance'),
                'overdue_customers'=>$overdue->count(), 'overdue_amount'=>(float)$overdue->sum('due_balance'),
                'upcoming_customers'=>$upcoming->count(), 'upcoming_amount'=>(float)$upcoming->sum('upcoming_balance'),
                'today_target'=>(float)($plan?->stops->whereIn('status',['planned','in_progress','completed'])->sum('collectible_balance') ?? 0),
                'collected_today'=>(float)$collected,
                'remaining_visits'=>$plan?->stops->whereIn('status',['planned','in_progress'])->count() ?? 0,
                'completed_visits'=>$plan?->stops->where('status','completed')->count() ?? 0,
                'failed_attempts'=>CollectionVisit::query()->where('collector_id',$collector->id)->whereDate('completed_at',$today)
                    ->whereNotIn('outcome',['payment_collected','partial_payment_collected','customer_already_paid'])->count(),
            ],
            'due_today'=>$dueToday->values(), 'overdue'=>$overdue->values(), 'upcoming'=>$upcoming->values(),
            'plan'=>$plan, 'settings'=>$this->settings(),
            'assignment_count'=>CollectorCustomerAssignment::where('collector_id',$collector->id)->whereNull('unassigned_at')->count(),
            'automation_enabled'=>(bool)config('collection_planner.automation_enabled',false)
                && (bool)Setting::get('collection_planner.auto_enabled',false),
        ];
    }

    public function generate(User $collector, bool $smart, ?float $startLat, ?float $startLng, ?string $startAddress, ?string $planningRunId = null, string $generationMode = 'manual', ?Carbon $planDate = null): CollectionPlan
    {
        $today = ($planDate ?: now(config('app.timezone', 'Asia/Manila')))->copy()->startOfDay();
        $existing = CollectionPlan::with('stops.visits')->where('collector_id',$collector->id)->whereDate('plan_date',$today)->first();
        if ($existing?->stops->contains(fn (CollectionPlanStop $stop) => $stop->status === 'in_progress')) return $existing;

        return DB::transaction(function () use ($collector,$smart,$startLat,$startLng,$startAddress,$today,$existing,$planningRunId,$generationMode) {
            $plan = $existing ?: CollectionPlan::create(['collector_id'=>$collector->id,'collection_planner_run_id'=>$planningRunId,'plan_date'=>$today,'generation_mode'=>$generationMode,'algorithm_version'=>config('collection_planner.algorithm_version'),'generated_at'=>now()]);
            $preserved = $plan->stops()->whereIn('status',['completed','skipped','in_progress'])->get();
            $plan->stops()->whereNotIn('id',$preserved->pluck('id'))->delete();
            $rows = $this->eligibleCustomers($collector,$today)->where('plan_eligible',true)
                ->reject(fn($row)=>$preserved->contains('customer_id',$row['customer']->id));
            $ordered = $this->order($rows,$smart,$startLat,$startLng);
            $settings = $this->settings();
            $capacity = max(1, intdiv($this->workingMinutes($settings), max(1,$settings->estimated_visit_minutes))) - $preserved->where('status','completed')->count();
            $sequence = (int)$preserved->max('sequence');
            foreach ($ordered->values() as $index=>$row) {
                CollectionPlanStop::create([
                    'collection_plan_id'=>$plan->id,'customer_id'=>$row['customer']->id,'sequence'=>$sequence+$index+1,
                    'priority_level'=>$row['priority_level'],'priority_score'=>$row['priority_score'],
                    'address_group'=>$row['address_group'],'location_source'=>$row['location_source'],
                    'latitude'=>$row['coordinates']['latitude']??null,'longitude'=>$row['coordinates']['longitude']??null,
                    'straight_line_km'=>$row['straight_line_km']??null,'attempt_count'=>$row['attempt_count'],
                    'collectible_balance'=>$row['collectible_balance'],'earliest_due_date'=>$row['earliest_due_date'],
                    'status'=>$index < max(0,$capacity) ? 'planned' : 'unscheduled',
                ]);
            }
            $plan->update(['smart_enabled'=>$smart,'start_latitude'=>$startLat,'start_longitude'=>$startLng,
                'start_address'=>$startAddress,'route_mode'=>$smart?'urgency_gps_address_hybrid':'urgency_only',
                'collection_planner_run_id'=>$planningRunId ?: $plan->collection_planner_run_id,
                'generation_mode'=>$planningRunId ? $generationMode : ($plan->generation_mode ?: $generationMode),
                'algorithm_version'=>config('collection_planner.algorithm_version'),
                'version'=>($plan->version?:0)+1,'generated_at'=>now()]);
            return $plan->fresh(['stops.customer:id,account_number,full_name,address,contact_number,gps_coordinates','stops.visits']);
        });
    }

    private function order(Collection $rows, bool $smart, ?float $startLat, ?float $startLng): Collection
    {
        $rows = $rows->sortBy([['priority_score','desc'],['earliest_due_date','asc'],['address_group','asc']])->values();
        if (!$smart) return $rows;
        $currentLat=$startLat; $currentLng=$startLng; $ordered=collect();
        foreach ($rows->groupBy('priority_level') as $group) {
            $gps=$group->filter(fn ($row) => $row['coordinates'] !== null)->values();
            while ($gps->isNotEmpty()) {
                $next = ($currentLat!==null&&$currentLng!==null)
                    ? $gps->sortBy(fn($row)=>$this->haversine($currentLat,$currentLng,$row['coordinates']['latitude'],$row['coordinates']['longitude']))->first()
                    : $gps->first();
                if ($currentLat!==null&&$currentLng!==null) $next['straight_line_km']=round($this->haversine($currentLat,$currentLng,$next['coordinates']['latitude'],$next['coordinates']['longitude']),3);
                $ordered->push($next); $gps=$gps->reject(fn($row)=>$row['customer']->id===$next['customer']->id)->values();
                $currentLat=$next['coordinates']['latitude']; $currentLng=$next['coordinates']['longitude'];
            }
            $group->filter(fn ($row) => $row['coordinates'] === null)
                ->sortBy(fn ($row) => ($row['dhcp_live'] ? '0' : '1').'|'.$row['address_group'].'|'.mb_strtolower($row['customer']->full_name))
                ->each(fn ($row) => $ordered->push($row));
        }
        return $ordered;
    }

    private function coordinates(mixed $value): ?array
    {
        return is_array($value) && is_numeric($value['latitude']??null) && is_numeric($value['longitude']??null)
            ? ['latitude'=>(float)$value['latitude'],'longitude'=>(float)$value['longitude']] : null;
    }

    private function addressGroup(string $address): string
    {
        $normalized = trim((string)preg_replace('/\s+/', ' ', mb_strtolower($address)));
        preg_match('/\b(?:brgy\.?|barangay)\s+[^,]+/iu', $normalized, $barangayMatch);
        preg_match('/\b(?:phase|ph\.?)[\s-]*([a-g]|[1-7])\b/iu', $normalized, $phaseMatch);
        $barangay = isset($barangayMatch[0]) ? mb_convert_case($barangayMatch[0], MB_CASE_TITLE, 'UTF-8') : null;
        $phase = isset($phaseMatch[1]) ? 'Phase '.mb_strtoupper($phaseMatch[1], 'UTF-8') : null;
        if ($barangay && $phase) return $barangay.' · '.$phase;
        if ($phase) return $phase;
        if ($barangay) return $barangay;
        return $normalized !== '' ? mb_convert_case($normalized,MB_CASE_TITLE,'UTF-8') : 'Address not recorded';
    }

    private function haversine(float $aLat,float $aLng,float $bLat,float $bLng): float
    {
        $earth=6371; $dLat=deg2rad($bLat-$aLat); $dLng=deg2rad($bLng-$aLng);
        $a=sin($dLat/2)**2+cos(deg2rad($aLat))*cos(deg2rad($bLat))*sin($dLng/2)**2;
        return $earth*2*atan2(sqrt($a),sqrt(1-$a));
    }

    private function workingMinutes(CollectionPlannerSetting $settings): int
    {
        $minutes=Carbon::parse($settings->shift_start)->diffInMinutes(Carbon::parse($settings->shift_end));
        if($settings->break_start&&$settings->break_end)$minutes-=Carbon::parse($settings->break_start)->diffInMinutes(Carbon::parse($settings->break_end));
        return max(0,$minutes);
    }
}
