<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerCredit extends Model
{
    use HasUuids;

    protected $fillable = ['customer_id', 'payment_id', 'original_amount', 'remaining_amount', 'notes'];

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:2', 'remaining_amount' => 'decimal:2'];
    }
}
