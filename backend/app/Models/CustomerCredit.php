<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCredit extends Model
{
    use BelongsToTenantThroughParent, HasUuids;

    public function tenantOwnershipRelation(): string
    {
        return 'customer';
    }

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

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
