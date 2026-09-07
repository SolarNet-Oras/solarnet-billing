<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyCashCount extends Model
{
    use HasUuids;

    protected $fillable = [
        'count_date', 'breakdown', 'counted_amount', 'expected_cash_balance',
        'variance', 'counted_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'count_date' => 'date',
            'breakdown' => 'array',
            'counted_amount' => 'decimal:2',
            'expected_cash_balance' => 'decimal:2',
            'variance' => 'decimal:2',
        ];
    }

    public function counter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
