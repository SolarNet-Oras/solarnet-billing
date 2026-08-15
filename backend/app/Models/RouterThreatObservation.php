<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterThreatObservation extends Model
{
    use HasUuids;

    protected $fillable = [
        'router_id',
        'feed_name',
        'remote_ip',
        'connection_directions',
        'status',
        'first_observed_at',
        'last_observed_at',
        'reviewed_by',
        'reviewed_at',
        'review_note',
        'blocked_at',
    ];

    protected function casts(): array
    {
        return [
            'connection_directions' => 'array',
            'first_observed_at' => 'datetime',
            'last_observed_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'blocked_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
