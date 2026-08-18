<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An explicitly approved NAS source for FreeRADIUS. The secret is encrypted
 * at rest in SolarNet and is never serialized through the staff API.
 */
class RadiusNasClient extends Model
{
    use HasUuids;

    protected $fillable = [
        'router_id', 'name', 'nas_address', 'shortname', 'shared_secret',
        'enabled', 'test_mode', 'last_synced_at', 'last_error', 'metadata',
    ];

    protected $hidden = ['shared_secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'test_mode' => 'boolean',
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function setSharedSecretAttribute(?string $value): void
    {
        $this->attributes['shared_secret'] = filled($value) ? encrypt($value) : null;
    }

    public function getSharedSecretAttribute(?string $value): ?string
    {
        return filled($value) ? decrypt($value) : null;
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
