<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationIncentiveAllocation extends Model
{
    use HasUuids;
    protected $fillable = ['pool_id','user_id','attendance_record_id','payroll_disbursement_id','work_date','share_amount','status'];
    protected $casts = ['work_date'=>'date','share_amount'=>'float'];
    public function pool(): BelongsTo { return $this->belongsTo(InstallationIncentivePool::class, 'pool_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
