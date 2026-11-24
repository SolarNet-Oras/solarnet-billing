<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class PaymentTransfer extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id','payment_id','source_invoice_id','target_invoice_id','source_customer_id','target_customer_id','amount','retained_credit','reason','idempotency_key','created_by'];
    protected $casts = ['amount' => 'decimal:2', 'retained_credit' => 'decimal:2'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payment transfer records are immutable.'));
        static::deleting(fn () => throw new LogicException('Payment transfer records are immutable.'));
    }
}
