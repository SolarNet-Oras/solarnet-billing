<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only operational audit; deliberately contains no RADIUS secret. */
class RadiusAuthorizationLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'radius_subscriber_id', 'customer_id', 'router_id', 'actor_id',
        'event', 'decision', 'reason', 'source', 'metadata',
    ];

    protected function casts(): array { return ['metadata' => 'array']; }

    public function subscriber(): BelongsTo { return $this->belongsTo(RadiusSubscriber::class, 'radius_subscriber_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function router(): BelongsTo { return $this->belongsTo(Router::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_id'); }
}
