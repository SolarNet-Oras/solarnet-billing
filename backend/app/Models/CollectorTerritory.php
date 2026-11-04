<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectorTerritory extends Model
{
    use HasUuids;
    protected $fillable = ['collector_id','name','address_pattern','is_fallback','is_active','priority','max_daily_visits','start_latitude','start_longitude','start_address','created_by'];
    protected $casts = ['is_fallback'=>'boolean','is_active'=>'boolean','priority'=>'integer','max_daily_visits'=>'integer','start_latitude'=>'float','start_longitude'=>'float'];
    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
