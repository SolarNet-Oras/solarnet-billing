<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\AutomaticCollectionPlannerService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class GenerateDailyCollectionPlans extends Command
{
    protected $signature = 'collection:generate-daily-plans
        {--date= : Manila plan date; defaults to today}
        {--dry-run : Preview eligibility and assignments without writes}
        {--execute : Create assignments and plans}
        {--confirm= : Required confirmation for a manually triggered execution}
        {--triggered-by=manual}';

    protected $description = 'Idempotently assign eligible collections and generate daily collector plans';

    public function handle(AutomaticCollectionPlannerService $planner): int
    {
        $timezone=(string)config('collection_planner.timezone','Asia/Manila');
        $date=$this->option('date')
            ? Carbon::createFromFormat('Y-m-d',(string)$this->option('date'),$timezone)->startOfDay()
            : now($timezone)->startOfDay();
        $execute=(bool)$this->option('execute');
        $dryRun=(bool)$this->option('dry-run') || !$execute;
        $trigger=(string)$this->option('triggered-by');

        if($execute && app()->environment('production') && !$date->isSameDay(now($timezone))){
            $this->error('Production automatic planning may execute only for today. Historical recovery requires a separate approved command.');
            return self::FAILURE;
        }
        if($execute && $trigger!=='schedule' && $this->option('confirm')!=='GENERATE DAILY COLLECTION PLANS'){
            $this->error('Manual execution requires --confirm="GENERATE DAILY COLLECTION PLANS".');
            return self::FAILURE;
        }
        if($execute && (!(bool)config('collection_planner.automation_enabled',false)
            || !(bool)Setting::get('collection_planner.auto_enabled',false))){
            $this->warn('Automatic collection planning is disabled by the environment or application setting. No changes were made.');
            return self::SUCCESS;
        }

        $lock=Cache::lock('collection-planner:daily:'.$date->toDateString(),1800);
        if(!$lock->get()){$this->error('Another collection planning run is active for this date.');return self::FAILURE;}
        try{$run=$planner->run($date,$dryRun,$trigger);}
        finally{$lock->release();}

        $summary=[
            'run_id'=>$run->id,'run_date'=>$run->run_date?->toDateString(),'status'=>$run->status,
            'dry_run'=>$run->dry_run,'eligible_customers'=>$run->eligible_customers,
            'new_assignments'=>$run->new_assignments,'unassigned_exceptions'=>$run->unassigned_exceptions,
            'collectors_processed'=>$run->collectors_processed,'plans_generated'=>$run->plans_generated,
            'scheduled_visits'=>$run->scheduled_visits,'unscheduled_visits'=>$run->unscheduled_visits,
            'details'=>$run->summary,'failure_reason'=>$run->failure_reason,
        ];
        $this->line(json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        return in_array($run->status,['success'],true)?self::SUCCESS:self::FAILURE;
    }
}
