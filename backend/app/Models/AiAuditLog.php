<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiAuditLog extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'conversation_id',
        'tool_name',
        'arguments',
        'result',
        'latency_ms',
        'status',
        'error',
    ];

    protected $casts = [
        'arguments' => 'array',
        'result'    => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
