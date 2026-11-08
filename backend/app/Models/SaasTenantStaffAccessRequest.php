<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasTenantStaffAccessRequest extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'name', 'email', 'phone', 'requested_role', 'password_encrypted', 'status', 'reviewed_by', 'reviewed_at', 'review_notes'];
    protected $hidden = ['password_encrypted'];
    protected function casts(): array { return ['reviewed_at' => 'datetime']; }
    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
