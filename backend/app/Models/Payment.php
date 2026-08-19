<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Payment extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'invoice_id',
        'customer_id',
        'collector_id',
        'received_by',
        'remittance_id',
        'payment_number',
        'amount',
        'cash_counted_amount',
        'cash_change_amount',
        'cash_breakdown',
        'payer_signature',
        'payer_signature_similarity',
        'signature_signer_type',
        'signature_signer_name',
        'payment_method',
        'payment_date',
        'transaction_id',
        'reference',
        'notes',
        'payment_confirmation_email_sent_at',
    ];

    protected $casts = [
        'amount' => 'float',
        'cash_counted_amount' => 'float',
        'cash_change_amount' => 'float',
        'cash_breakdown' => 'array',
        'payer_signature_similarity' => 'float',
        'payment_date' => 'date',
        'payment_confirmation_email_sent_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_id'); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
    public function remittance(): BelongsTo { return $this->belongsTo(Remittance::class); }
    public function paymongoCheckout(): HasOne { return $this->hasOne(PaymongoCheckout::class, 'payment_id'); }
}
