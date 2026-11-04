<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CollectionPlan extends Model
{
    use HasUuids;
    protected $fillable = ['collector_id','plan_date','status','smart_enabled','start_latitude','start_longitude','start_address','route_mode','version','generated_at'];
    protected $casts = ['plan_date'=>'date','smart_enabled'=>'boolean','start_latitude'=>'float','start_longitude'=>'float','version'=>'integer','generated_at'=>'datetime'];
    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_id'); }
    public function stops(): HasMany { return $this->hasMany(CollectionPlanStop::class)->orderBy('sequence'); }
}
