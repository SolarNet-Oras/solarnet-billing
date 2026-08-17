<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerTroubleshootingSession extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'customer_id', 'status', 'stage', 'state', 'messages', 'diagnosis',
        'ticket_id', 'expires_at', 'completed_at',
    ];

    protected $casts = [
        'state' => 'array',
        'messages' => 'array',
        'diagnosis' => 'array',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
}
