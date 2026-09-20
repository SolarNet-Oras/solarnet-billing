<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'actor_id', 'actor_name', 'actor_type', 'category', 'action', 'method', 'path',
        'subject_type', 'subject_id', 'response_status', 'changes', 'ip_address',
        'user_agent', 'duration_ms',
    ];

    protected $casts = ['changes' => 'array', 'response_status' => 'integer', 'duration_ms' => 'integer'];
}
