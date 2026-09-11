<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffCompensation extends Model
{
    use HasUuids;

    protected $table = 'staff_compensations';

    protected $fillable = ['user_id', 'monthly_salary', 'daily_rate', 'monthly_allowance', 'monthly_deduction', 'work_days_per_month', 'scheduled_start', 'scheduled_end', 'grace_minutes', 'overtime_multiplier', 'updated_by'];
    protected $casts = ['monthly_salary'=>'float', 'daily_rate'=>'float', 'monthly_allowance'=>'float', 'monthly_deduction'=>'float', 'work_days_per_month'=>'integer', 'grace_minutes'=>'integer', 'overtime_multiplier'=>'float'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
