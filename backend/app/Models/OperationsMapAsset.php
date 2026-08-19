<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manually recorded physical network reference point or route.
 *
 * This model intentionally contains no RouterOS, OLT, ONU, or billing write
 * behaviour. NAPs, poles, and fiber routes are field-map data entered by an
 * authorized staff member after the physical location has been verified.
 */
class OperationsMapAsset extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'asset_type',
        'name',
        'latitude',
        'longitude',
        'route_coordinates',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'route_coordinates' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return array<string, mixed> */
    public function toMapArray(): array
    {
        return [
            'id' => $this->id,
            'asset_type' => $this->asset_type,
            'name' => $this->name,
            'latitude' => $this->latitude === null ? null : (float) $this->latitude,
            'longitude' => $this->longitude === null ? null : (float) $this->longitude,
            'route_coordinates' => $this->route_coordinates,
            'status' => $this->status,
            'notes' => $this->notes,
            'created_by' => $this->relationLoaded('createdBy') && $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
