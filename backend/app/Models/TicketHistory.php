<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketHistory extends Model
{
    use BelongsToTenantThroughParent, HasUuids;

    public function tenantOwnershipRelation(): string { return 'ticket'; }

    protected $fillable = ['ticket_id', 'user_id', 'role', 'action', 'previous_status', 'new_status', 'notes', 'metadata'];
    protected $casts = ['metadata' => 'array'];

    public function ticket(): BelongsTo { return $this->belongsTo(Ticket::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
