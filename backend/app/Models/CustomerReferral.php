<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReferral extends Model
{
    use HasUuids;

    protected $fillable = [
        'referrer_customer_id', 'referred_customer_id', 'prospect_name',
        'phone', 'phone_normalized', 'email', 'email_normalized', 'address',
        'status', 'reward_choice', 'reward_amount', 'qualified_at',
        'choice_at', 'rewarded_at', 'notes',
        'cash_paid_by', 'cash_financial_entry_id', 'cash_paid_at', 'cash_payout_reference',
    ];

    protected function casts(): array
    {
        return [
            'reward_amount' => 'decimal:2',
            'qualified_at' => 'datetime',
            'choice_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'cash_paid_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo { return $this->belongsTo(Customer::class, 'referrer_customer_id'); }
    public function referredCustomer(): BelongsTo { return $this->belongsTo(Customer::class, 'referred_customer_id'); }
    public function cashPayer(): BelongsTo { return $this->belongsTo(User::class, 'cash_paid_by'); }
    public function cashFinancialEntry(): BelongsTo { return $this->belongsTo(FinancialEntry::class, 'cash_financial_entry_id'); }
}
