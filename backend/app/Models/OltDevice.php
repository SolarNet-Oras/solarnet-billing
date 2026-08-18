<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OltDevice extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'host',
        'snmp_port',
        'snmp_version',
        'snmp_community',
        'location',
        'model',
        'notes',
        'is_active',
        'connection_status',
        'last_checked_at',
        'last_snapshot',
    ];

    protected $hidden = [
        'snmp_community',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'snmp_port' => 'integer',
            'last_checked_at' => 'datetime',
            'last_snapshot' => 'array',
        ];
    }

    /** SNMP community strings are stored encrypted and never returned by APIs. */
    public function setSnmpCommunityAttribute(?string $value): void
    {
        $this->attributes['snmp_community'] = filled($value) ? encrypt($value) : null;
    }

    public function getSnmpCommunityAttribute(?string $value): ?string
    {
        return filled($value) ? decrypt($value) : null;
    }

    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'host' => $this->host,
            'snmp_port' => $this->snmp_port,
            'snmp_version' => $this->snmp_version,
            'has_snmp_community' => filled($this->snmp_community),
            'location' => $this->location,
            'model' => $this->model,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'connection_status' => $this->connection_status,
            'last_checked_at' => $this->last_checked_at,
            'last_snapshot' => $this->last_snapshot,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
