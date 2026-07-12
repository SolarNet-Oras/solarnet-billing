<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DhcpLease extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'router_id',
        'customer_id',
        'mac_address',
        'ip_address',
        'hostname',
        'comment',
        'rate_limit',
        'is_dynamic',
        'status',
        'server',
        'expires_at',
        'last_seen_at',
        'is_matched',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_matched' => 'boolean',
        'is_dynamic' => 'boolean',
    ];

    /**
     * Get the router this lease belongs to
     */
    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * Get the customer this lease is matched to
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Scope: Unmatched leases (no customer)
     */
    public function scopeUnmatched($query)
    {
        return $query->where('is_matched', false)->whereNull('customer_id');
    }

    /**
     * Scope: Active leases
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'bound');
    }
}
