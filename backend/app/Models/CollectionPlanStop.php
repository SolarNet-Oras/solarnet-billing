<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionPlanStop extends Model
{
    use BelongsToTenantThroughParent, HasUuids;
    public function tenantOwnershipRelation(): string { return 'plan'; }
    protected $fillable = ['collection_plan_id','customer_id','sequence','priority_level','priority_score','address_group','location_source','latitude','longitude','straight_line_km','attempt_count','collectible_balance','earliest_due_date','status','scheduled_at','completed_at','skip_reason'];
    protected $casts = ['sequence'=>'integer','priority_score'=>'integer','latitude'=>'float','longitude'=>'float','straight_line_km'=>'float','attempt_count'=>'integer','collectible_balance'=>'float','earliest_due_date'=>'date','scheduled_at'=>'datetime','completed_at'=>'datetime'];
    public function plan(): BelongsTo { return $this->belongsTo(CollectionPlan::class, 'collection_plan_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function visits(): HasMany { return $this->hasMany(CollectionVisit::class); }
}
