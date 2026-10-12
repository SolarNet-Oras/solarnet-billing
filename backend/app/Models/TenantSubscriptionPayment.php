<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSubscriptionPayment extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'subscription_id', 'plan_id', 'provider', 'provider_payment_id', 'provider_reference', 'idempotency_key', 'amount', 'currency', 'status', 'verified_at', 'verified_by', 'failure_reason', 'provider_payload'];
    protected $hidden = ['provider_payload'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'verified_at' => 'datetime', 'provider_payload' => 'array']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(TenantSubscription::class, 'subscription_id'); }
    public function plan(): BelongsTo { return $this->belongsTo(SaasSubscriptionPlan::class, 'plan_id'); }
    public function verifier(): BelongsTo { return $this->belongsTo(User::class, 'verified_by'); }
}
