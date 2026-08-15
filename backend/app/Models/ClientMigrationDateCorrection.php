<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ClientMigrationDateCorrection extends Model
{
    use HasUuids;

    protected $fillable = [
        'client_migration_audit_id', 'customer_id', 'user_id', 'customer_name',
        'old_installation_date', 'new_installation_date', 'source',
    ];

    protected $casts = [
        'old_installation_date' => 'date',
        'new_installation_date' => 'date',
    ];
}
