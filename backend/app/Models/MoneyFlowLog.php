<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class MoneyFlowLog extends Model
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'event_type', 'source_type', 'source_id', 'amount',
        'payment_method', 'effect_type', 'source_wallet', 'destination_wallet',
        'metadata', 'occurred_at', 'recorded_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'metadata' => 'array', 'occurred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Money-flow logs are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Money-flow logs cannot be deleted.'));
    }
}
