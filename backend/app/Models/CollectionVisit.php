<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CollectionVisit extends Model
{
    use HasUuids;
    protected $fillable = ['collection_plan_stop_id','collector_id','customer_id','status','outcome','arrived_at','started_at','completed_at','latitude','longitude','accuracy_meters','distance_from_customer_meters','gps_verified','requires_review','location_exception_reason','remarks','photo_path','payment_id','idempotency_key'];
    protected $casts = ['arrived_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','latitude'=>'float','longitude'=>'float','accuracy_meters'=>'float','distance_from_customer_meters'=>'float','gps_verified'=>'boolean','requires_review'=>'boolean'];
    protected $hidden = ['photo_path'];
    public function stop(): BelongsTo { return $this->belongsTo(CollectionPlanStop::class, 'collection_plan_stop_id'); }
    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function invoices(): BelongsToMany { return $this->belongsToMany(Invoice::class, 'collection_visit_invoices')->withPivot('balance_at_visit')->withTimestamps(); }
}
