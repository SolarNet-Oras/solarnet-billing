<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantUsageSnapshot extends Model
{
    use HasUuids;
    protected $fillable = ['tenant_id', 'snapshot_date', 'metrics'];
    protected function casts(): array { return ['snapshot_date' => 'date', 'metrics' => 'array']; }
}
