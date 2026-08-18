<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SolarNet's audited subscriber-policy projection. It is not a replacement
 * for customers, invoices, payments, or the current Simple Queue workflow.
 */
class RadiusSubscriber extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_id', 'router_id', 'radius_username', 'mac_address',
        'ip_address', 'authorization_status', 'billing_status', 'rate_limit',
        'restricted_rate_limit', 'requires_captive_portal', 'mac_conflict',
        'last_synced_at', 'last_authenticated_at', 'last_accounting_at',
        'last_error', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requires_captive_portal' => 'boolean',
            'mac_conflict' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_authenticated_at' => 'datetime',
            'last_accounting_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function router(): BelongsTo { return $this->belongsTo(Router::class); }
    public function authorizationLogs(): HasMany { return $this->hasMany(RadiusAuthorizationLog::class); }
    public function accountingSessions(): HasMany { return $this->hasMany(RadiusAccountingSession::class); }
}
