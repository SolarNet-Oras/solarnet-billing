<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymongoCheckout extends Model
{
    use HasUuids;

    protected $fillable = ['invoice_id', 'customer_id', 'checkout_session_id', 'reference_number', 'amount', 'status', 'paid_at', 'payment_id'];
    protected function casts(): array { return ['amount' => 'float', 'paid_at' => 'datetime']; }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
}
