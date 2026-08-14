<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'ticket_number',
        'customer_id',
        'assigned_to',
        'subject',
        'description',
        'status',
        'priority',
        'category',
        'ticket_type',
        'workflow_status',
        'claimed_at',
        'started_at',
        'resolution_notes',
        'repair_details',
        'installation_mac',
        'installation_notes',
        'submitted_for_approval_at',
        'approved_by',
        'approved_at',
        'return_reason',
        'returned_at',
        'registered_at',
        'closed_by',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'claimed_at' => 'datetime',
        'started_at' => 'datetime',
        'repair_details' => 'array',
        'submitted_for_approval_at' => 'datetime',
        'approved_at' => 'datetime',
        'returned_at' => 'datetime',
        'registered_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** Relationship name deliberately avoids colliding with the assigned_to UUID in JSON. */
    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    public function histories(): HasMany { return $this->hasMany(TicketHistory::class)->orderBy('created_at'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function closedBy(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeUrgent($query)
    {
        return $query->where('priority', 'urgent');
    }
}
