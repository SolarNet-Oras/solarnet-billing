<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Remittance extends Model
{
    use HasUuids;

    protected $fillable = ['collector_id', 'liquidated_by', 'received_by', 'declared_amount', 'cash_counted_amount', 'cash_breakdown', 'received_amount', 'status', 'notes', 'submitted_at', 'liquidated_at', 'received_at'];
    protected $casts = ['declared_amount' => 'float', 'cash_counted_amount' => 'float', 'cash_breakdown' => 'array', 'received_amount' => 'float', 'submitted_at' => 'datetime', 'liquidated_at' => 'datetime', 'received_at' => 'datetime'];

    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_id'); }
    public function liquidator(): BelongsTo { return $this->belongsTo(User::class, 'liquidated_by'); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function payments(): HasMany { return $this->hasMany(Payment::class); }
}
