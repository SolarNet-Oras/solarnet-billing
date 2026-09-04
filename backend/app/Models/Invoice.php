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
        'generation_source',
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
        'initial_email_status',
        'initial_email_attempt_count',
        'initial_email_last_attempt_at',
        'initial_email_sent_at',
        'initial_email_failure_reason',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'due_date' => 'date',
        'billing_period_start' => 'date',
        'billing_period_end' => 'date',
        'recurring_cycle_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'sent_at' => 'datetime',
        'paid_at' => 'datetime',
        'initial_email_attempt_count' => 'integer',
        'initial_email_last_attempt_at' => 'datetime',
        'initial_email_sent_at' => 'datetime',
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

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function billingSmsNotifications(): HasMany
    {
        return $this->hasMany(BillingSmsNotification::class);
    }

    public function finalGracePeriodWarnings(): HasMany
    {
        return $this->hasMany(FinalGracePeriodWarning::class);
    }

    public function isOverdue(): bool
    {
        return $this->due_date->lt(now(config('app.timezone', 'Asia/Manila'))->startOfDay()) && $this->balance > 0;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid' && $this->balance <= 0;
    }

    /**
     * Only recurring invoices participate in automatic creation-time and
     * recurring billing email/SMS campaigns. Manual, collector, and migration
     * invoices remain payable without starting an automatic reminder campaign.
     */
    public function allowsAutomaticBillingNotifications(): bool
    {
        return $this->generation_source === 'recurring';
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
