<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AiPendingAction extends Model
{
    use HasUuids;

    protected $fillable = ['user_id', 'conversation_id', 'action', 'payload', 'status', 'expires_at', 'confirmed_at'];
    protected function casts(): array { return ['payload' => 'array', 'expires_at' => 'datetime', 'confirmed_at' => 'datetime']; }
}
