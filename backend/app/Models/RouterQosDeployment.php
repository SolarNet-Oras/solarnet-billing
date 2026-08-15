<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouterQosDeployment extends Model
{
    use HasUuids;

    protected $fillable = [
        'router_id',
        'configuration_version',
        'status',
        'strategy',
        'queue_type',
        'configuration',
        'inspection',
        'backup_filename',
        'backup_verified_at',
        'verification',
        'failure_reason',
        'created_by',
        'applied_by',
        'applied_at',
        'rolled_back_by',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'array',
            'inspection' => 'array',
            'verification' => 'array',
            'backup_verified_at' => 'datetime',
            'applied_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    public function router(): BelongsTo { return $this->belongsTo(Router::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function applier(): BelongsTo { return $this->belongsTo(User::class, 'applied_by'); }
    public function rollbackUser(): BelongsTo { return $this->belongsTo(User::class, 'rolled_back_by'); }
}
