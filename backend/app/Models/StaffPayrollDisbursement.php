<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffPayrollDisbursement extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'cutoff_start', 'cutoff_end', 'pay_date', 'base_pay', 'overtime_pay',
        'allowance', 'gross_pay', 'late_deduction', 'sss_deduction', 'philhealth_deduction',
        'pagibig_deduction', 'cash_advance_deduction', 'other_deductions', 'total_deductions',
        'net_pay', 'present_days', 'worked_minutes', 'overtime_minutes', 'status',
        'prepared_at', 'released_at', 'payslip_emailed_at', 'email_error', 'calculation_snapshot',
    ];

    protected $casts = [
        'cutoff_start'=>'date', 'cutoff_end'=>'date', 'pay_date'=>'date',
        'prepared_at'=>'datetime', 'released_at'=>'datetime', 'payslip_emailed_at'=>'datetime',
        'base_pay'=>'float', 'overtime_pay'=>'float', 'allowance'=>'float', 'gross_pay'=>'float',
        'late_deduction'=>'float', 'sss_deduction'=>'float', 'philhealth_deduction'=>'float',
        'pagibig_deduction'=>'float', 'cash_advance_deduction'=>'float', 'other_deductions'=>'float',
        'total_deductions'=>'float', 'net_pay'=>'float', 'calculation_snapshot'=>'array',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
