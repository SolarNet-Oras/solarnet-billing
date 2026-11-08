<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCashoutRequest extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id','requested_by','amount','gcash_name','gcash_number','status','reviewed_by','transfer_reference','review_notes','sent_at'];
    protected function casts(): array { return ['amount'=>'decimal:2','sent_at'=>'datetime']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
