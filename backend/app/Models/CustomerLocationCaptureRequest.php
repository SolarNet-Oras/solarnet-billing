<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLocationCaptureRequest extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_id', 'router_id', 'dhcp_lease_id', 'onu_reference', 'token_hash',
        'source_ip', 'status', 'requested_at', 'shown_at', 'accepted_at', 'completed_at',
        'expired_at', 'latitude', 'longitude', 'accuracy_meters', 'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime', 'shown_at' => 'datetime', 'accepted_at' => 'datetime',
            'completed_at' => 'datetime', 'expired_at' => 'datetime', 'captured_at' => 'datetime',
            'latitude' => 'float', 'longitude' => 'float', 'accuracy_meters' => 'float',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function lease(): BelongsTo { return $this->belongsTo(DhcpLease::class, 'dhcp_lease_id'); }
}
