<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaasSubscriptionPlan extends Model
{
    use HasUuids, SoftDeletes;
    protected $fillable = ['code', 'name', 'description', 'amount', 'currency', 'billing_interval', 'trial_days', 'grace_days', 'features', 'is_active'];
    protected function casts(): array { return ['amount' => 'decimal:2', 'features' => 'array', 'is_active' => 'boolean']; }
    public function subscriptions(): HasMany { return $this->hasMany(TenantSubscription::class, 'plan_id'); }
}
