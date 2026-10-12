<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantOnboardingAttempt extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'company_fingerprint', 'email_hash', 'phone_hash', 'ip_hash', 'status', 'context'];
    protected function casts(): array { return ['context' => 'array']; }
}
