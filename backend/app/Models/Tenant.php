<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Tenant extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['slug', 'name', 'legal_name', 'logo_path', 'address', 'contact_name', 'contact_email', 'contact_phone', 'status', 'is_primary', 'subscription_exempt', 'metadata'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'subscription_exempt' => 'boolean', 'metadata' => 'array'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_user')->withPivot(['id', 'membership_role', 'status', 'is_owner'])->withTimestamps();
    }

    public function memberships(): HasMany { return $this->hasMany(TenantMembership::class); }
    public function subscription(): HasOne { return $this->hasOne(TenantSubscription::class); }
    public function subscriptionPayments(): HasMany { return $this->hasMany(TenantSubscriptionPayment::class); }
    public function subscriptionEvents(): HasMany { return $this->hasMany(TenantSubscriptionEvent::class); }
}
