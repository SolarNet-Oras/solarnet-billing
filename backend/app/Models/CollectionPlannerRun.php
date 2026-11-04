<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionPlannerRun extends Model
{
    use HasUuids;
    protected $fillable = ['run_date','idempotency_key','trigger','status','algorithm_version','dry_run','eligible_customers','new_assignments','unassigned_exceptions','collectors_processed','plans_generated','scheduled_visits','unscheduled_visits','summary','failure_reason','started_at','finished_at'];
    protected $casts = ['run_date'=>'date','dry_run'=>'boolean','summary'=>'array','started_at'=>'datetime','finished_at'=>'datetime','eligible_customers'=>'integer','new_assignments'=>'integer','unassigned_exceptions'=>'integer','collectors_processed'=>'integer','plans_generated'=>'integer','scheduled_visits'=>'integer','unscheduled_visits'=>'integer'];
    public function plans(): HasMany { return $this->hasMany(CollectionPlan::class); }
}
