<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaasTenantApplication extends Model
{
    use HasUuids;

    protected $fillable = [
        'plan_id', 'company_name', 'tenant_slug', 'owner_name', 'owner_email',
        'owner_phone', 'password_hash', 'business_address', 'business_registration_number',
        'status', 'verification_token_hash', 'verification_expires_at', 'email_verified_at',
        'submitted_at', 'reviewed_by', 'reviewed_at', 'review_notes', 'approved_tenant_id',
        'ip_hash', 'user_agent',
    ];

    protected $hidden = ['password_hash', 'verification_token_hash', 'ip_hash'];

    protected function casts(): array
    {
        return [
            'verification_expires_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo { return $this->belongsTo(SaasSubscriptionPlan::class, 'plan_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function approvedTenant(): BelongsTo { return $this->belongsTo(Tenant::class, 'approved_tenant_id'); }
}
