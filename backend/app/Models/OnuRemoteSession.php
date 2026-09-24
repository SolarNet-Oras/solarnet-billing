<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnuRemoteSession extends Model
{
    use HasUuids;

    protected $fillable = ['customer_id','router_id','created_by','customer_ip','source_ip','target_port','public_port','public_host','path','router_comment','status','expires_at','closed_at','last_error'];
    protected $casts = ['target_port'=>'integer','public_port'=>'integer','expires_at'=>'datetime','closed_at'=>'datetime'];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function router(): BelongsTo { return $this->belongsTo(Router::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
