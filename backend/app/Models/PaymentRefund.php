<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    use HasUuids;

    protected $fillable = [
        'payment_id', 'financial_entry_id', 'refunded_by', 'amount',
        'payment_method', 'reason', 'refunded_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_at' => 'datetime'];
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function financialEntry(): BelongsTo { return $this->belongsTo(FinancialEntry::class); }
    public function refunder(): BelongsTo { return $this->belongsTo(User::class, 'refunded_by'); }
}
