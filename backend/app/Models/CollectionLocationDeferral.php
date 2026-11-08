<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionLocationDeferral extends Model
{
    use BelongsToTenantThroughParent, HasUuids;

    public function tenantOwnershipRelation(): string { return 'customer'; }

    protected $fillable = ['customer_id','collector_id','collection_plan_stop_id','context','reason','deferred_at'];
    protected $casts = ['deferred_at'=>'datetime'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_id'); }
    public function stop(): BelongsTo { return $this->belongsTo(CollectionPlanStop::class, 'collection_plan_stop_id'); }
}
