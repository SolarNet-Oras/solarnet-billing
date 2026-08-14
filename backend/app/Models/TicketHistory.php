<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketHistory extends Model
{
    use HasUuids;

    protected $fillable = ['ticket_id', 'user_id', 'role', 'action', 'previous_status', 'new_status', 'notes', 'metadata'];
    protected $casts = ['metadata' => 'array'];

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
