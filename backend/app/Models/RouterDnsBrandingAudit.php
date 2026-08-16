<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable-enough audit trail for the guarded internal-DNS workflow.
 *
 * The backup filename is a RouterOS backup reference; its contents are never
 * copied into the database or exposed through the API.
 */
class RouterDnsBrandingAudit extends Model
{
    use HasUuids;

    protected $fillable = [
        'router_id', 'status', 'discovery', 'plan', 'backup_filename',
        'verification', 'failure_reason', 'discovered_by', 'approved_by',
        'applied_by', 'approved_at', 'applied_at', 'verified_at', 'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'discovery' => 'array',
            'plan' => 'array',
            'verification' => 'array',
            'approved_at' => 'datetime',
            'applied_at' => 'datetime',
            'verified_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo { return $this->belongsTo(Router::class); }
    public function discoverer(): BelongsTo { return $this->belongsTo(User::class, 'discovered_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function applier(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
}
