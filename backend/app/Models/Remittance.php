<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Remittance extends Model
{
    use HasUuids;

    protected $hidden = ['expense_receipt_path'];

    protected $fillable = ['collector_id', 'liquidated_by', 'received_by', 'cancelled_by', 'declared_amount', 'cash_counted_amount', 'cash_breakdown', 'liquidation_variance', 'shortage_reason', 'expense_receipt_reference', 'expense_receipt_path', 'variance_financial_entry_id', 'received_amount', 'status', 'notes', 'cancellation_reason', 'submitted_at', 'liquidated_at', 'received_at', 'cancelled_at'];
    protected $casts = ['declared_amount' => 'float', 'cash_counted_amount' => 'float', 'cash_breakdown' => 'array', 'liquidation_variance' => 'float', 'received_amount' => 'float', 'submitted_at' => 'datetime', 'liquidated_at' => 'datetime', 'received_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_id'); }
    public function liquidator(): BelongsTo { return $this->belongsTo(User::class, 'liquidated_by'); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}
