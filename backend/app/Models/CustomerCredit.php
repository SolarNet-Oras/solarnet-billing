<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerCredit extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_id', 'payment_id', 'covered_cycle_date',
        'covered_period_start', 'covered_period_end',
        'original_amount', 'remaining_amount', 'status', 'applied_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'covered_cycle_date' => 'date',
            'covered_period_start' => 'date',
            'covered_period_end' => 'date',
            'original_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
            'applied_at' => 'datetime',
        ];
    }
}
