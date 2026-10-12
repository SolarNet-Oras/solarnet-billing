<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMembership extends Model
{
    use HasUuids;

    protected $table = 'tenant_user';
    protected $fillable = ['tenant_id', 'user_id', 'membership_role', 'status', 'is_owner'];
    protected function casts(): array { return ['is_owner' => 'boolean']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
