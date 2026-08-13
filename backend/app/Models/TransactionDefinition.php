<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TransactionDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'type', 'description', 'payment_method', 'effect_type',
        'source_wallet', 'destination_wallet', 'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
