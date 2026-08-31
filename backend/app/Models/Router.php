<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Router extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'host',
        'port',
        'username',
        'password',
        'location',
        'notes',
        'dhcp_pool_name',
        'is_active',
        'connection_status',
        'routeros_version',
        'last_connected_at',
        'last_sync_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'port' => 'integer',
        'last_connected_at' => 'datetime',
        'last_sync_at' => 'datetime',
    ];

    /**
     * Encrypt password before saving
     */
    public function setPasswordAttribute($value): void
    {
        $this->attributes['password'] = encrypt($value);
    }

    /**
     * Decrypt password when accessing
     */
    public function getPasswordAttribute($value): ?string
    {
        return $value ? decrypt($value) : null;
    }

    /**
     * Get connection status with icon
     */
    public function getStatusIconAttribute(): string
    {
        return match($this->connection_status) {
            'online' => '🟢',
            'offline' => '🔴',
            default => '⚪',
        };
    }

    /** RADIUS/IPoE policy records only; RouterOS configuration remains separate. */
    public function radiusSubscribers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RadiusSubscriber::class);
    }

    /** Explicit FreeRADIUS NAS source approval; no RouterOS setting is implied. */
    public function radiusNasClient(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RadiusNasClient::class);
    }

    public function wireguardPeers(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WireguardPeer::class);
    }
}
