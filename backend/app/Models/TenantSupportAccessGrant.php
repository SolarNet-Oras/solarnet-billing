<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantSupportAccessGrant extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'granted_by', 'granted_to', 'reason', 'expires_at', 'revoked_at', 'revoked_by'];
    protected function casts(): array { return ['expires_at' => 'datetime', 'revoked_at' => 'datetime']; }
}
