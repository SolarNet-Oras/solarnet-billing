<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffCashAdvance extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'financial_entry_id', 'payroll_disbursement_id', 'amount',
        'settled_amount', 'status', 'issued_at', 'settled_at', 'notes',
    ];

    protected $casts = [
        'amount' => 'float', 'settled_amount' => 'float',
        'issued_at' => 'datetime', 'settled_at' => 'datetime',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function financialEntry(): BelongsTo { return $this->belongsTo(FinancialEntry::class); }
    public function payroll(): BelongsTo { return $this->belongsTo(StaffPayrollDisbursement::class, 'payroll_disbursement_id'); }
}
