<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TenantSubscription extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'plan_id', 'status', 'trial_starts_at', 'trial_ends_at', 'current_period_starts_at', 'current_period_ends_at', 'next_payment_due_at', 'payment_status', 'last_successful_payment_at', 'payment_provider', 'payment_reference', 'metadata'];
    protected function casts(): array { return ['trial_starts_at' => 'datetime', 'trial_ends_at' => 'datetime', 'current_period_starts_at' => 'datetime', 'current_period_ends_at' => 'datetime', 'next_payment_due_at' => 'datetime', 'last_successful_payment_at' => 'datetime', 'metadata' => 'array']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function plan(): BelongsTo { return $this->belongsTo(SaasSubscriptionPlan::class, 'plan_id'); }
    public function payments(): HasMany { return $this->hasMany(TenantSubscriptionPayment::class, 'subscription_id'); }
    public function events(): HasMany { return $this->hasMany(TenantSubscriptionEvent::class, 'subscription_id'); }
}
