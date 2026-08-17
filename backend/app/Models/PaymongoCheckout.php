<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymongoCheckout extends Model
{
    use HasUuids;

    protected $fillable = [
        'invoice_id', 'customer_id', 'account_number', 'checkout_session_id', 'checkout_type',
        'payment_intent_id', 'payment_method_id', 'paymongo_payment_id', 'payment_intent_client_key',
        'qr_image_url', 'webhook_event_id', 'reference_number', 'amount', 'status', 'paid_at',
        'expires_at', 'payment_id',
    ];
    protected function casts(): array { return ['amount' => 'float', 'paid_at' => 'datetime', 'expires_at' => 'datetime']; }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
