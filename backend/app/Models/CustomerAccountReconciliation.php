<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable operational audit of a financial-to-service reconciliation.
 * It never replaces invoices, payments, or credits as accounting records.
 */
class CustomerAccountReconciliation extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_id', 'payment_id', 'invoice_id', 'correlation_id', 'action',
        'financial_status', 'service_status', 'previous_service_status',
        'outstanding_balance', 'confirmed_payment_total', 'allocated_payment_total',
        'available_credit_total', 'restoration_eligible', 'restoration_status',
        'reason', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'outstanding_balance' => 'float',
            'confirmed_payment_total' => 'float',
            'allocated_payment_total' => 'float',
            'available_credit_total' => 'float',
            'restoration_eligible' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
