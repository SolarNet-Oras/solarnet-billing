<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'issue_date',
        'due_date',
        'billing_period_start',
        'billing_period_end',
        'recurring_cycle_date',
        'subtotal',
        'tax',
        'discount',
        'total',
        'paid_amount',
        'balance',
        'status',
        'notes',
        'sent_at',
        'paid_at',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'recurring_cycle_date' => 'date',
        'subtotal' => 'float',
        'tax' => 'float',
        'discount' => 'float',
        'total' => 'float',
        'paid_amount' => 'float',
        'balance' => 'float',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function isOverdue(): bool
    {
        return $this->due_date->lt(now(config('app.timezone', 'Asia/Manila'))->startOfDay()) && $this->balance > 0;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid' && $this->balance <= 0;
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_date', '<', now(config('app.timezone', 'Asia/Manila'))->startOfDay())
                    ->where('balance', '>', 0)
                    ->whereIn('status', ['sent', 'partial']);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('balance', '>', 0)
                    ->whereIn('status', ['sent', 'partial', 'overdue']);
    }
}
