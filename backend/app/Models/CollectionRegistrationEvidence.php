<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionRegistrationEvidence extends Model
{
    use BelongsToTenantThroughParent, HasUuids;
    public function tenantOwnershipRelation(): string { return 'customer'; }
    protected $table = 'collection_registration_evidence';
    protected $fillable = ['customer_id','collection_plan_stop_id','collector_id','router_label_photo_path',
        'submitted_email','submitted_phone','status','verified_mac_address','reviewed_by','reviewed_at','review_notes'];
    protected $casts = ['reviewed_at'=>'datetime'];
    protected $hidden = ['router_label_photo_path'];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function stop(): BelongsTo { return $this->belongsTo(CollectionPlanStop::class, 'collection_plan_stop_id'); }
}
