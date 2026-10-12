<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSubscriptionEvent extends Model
{
    use HasUuids;
    public const UPDATED_AT = null;
    protected $fillable = ['tenant_id', 'subscription_id', 'event_type', 'from_status', 'to_status', 'actor_user_id', 'source', 'idempotency_key', 'context', 'occurred_at'];
    protected function casts(): array { return ['context' => 'array', 'occurred_at' => 'datetime']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(TenantSubscription::class, 'subscription_id'); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
