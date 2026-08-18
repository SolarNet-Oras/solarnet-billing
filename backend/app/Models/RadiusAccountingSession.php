<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Retained accounting summary, not an unlimited raw packet archive. */
class RadiusAccountingSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'radius_subscriber_id', 'customer_id', 'router_id', 'session_id',
        'radius_username', 'mac_address', 'ip_address', 'nas_identifier',
        'session_started_at', 'last_interim_at', 'session_stopped_at',
        'session_duration_seconds', 'input_octets', 'output_octets',
        'termination_cause', 'status', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'session_started_at' => 'datetime', 'last_interim_at' => 'datetime',
            'session_stopped_at' => 'datetime', 'metadata' => 'array',
            'input_octets' => 'integer', 'output_octets' => 'integer',
            'session_duration_seconds' => 'integer',
        ];
    }

    public function subscriber(): BelongsTo { return $this->belongsTo(RadiusSubscriber::class, 'radius_subscriber_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function router(): BelongsTo { return $this->belongsTo(Router::class); }
}
