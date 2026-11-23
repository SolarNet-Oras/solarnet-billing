<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PaymentRefund extends Model
{
    use BelongsToTenantThroughParent, HasUuids;

    public function tenantOwnershipRelation(): string { return 'payment'; }

    protected $fillable = [
        'payment_id', 'financial_entry_id', 'refunded_by', 'amount',
        'payment_method', 'reason', 'refunded_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'refunded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Refund records cannot be deleted; create a correcting reversal instead.'));
        static::created(function (self $refund): void {
            app(\App\Services\MoneyFlowLogService::class)->refund($refund);
        });
    }

    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function financialEntry(): BelongsTo { return $this->belongsTo(FinancialEntry::class); }
    public function refunder(): BelongsTo { return $this->belongsTo(User::class, 'refunded_by'); }
}
