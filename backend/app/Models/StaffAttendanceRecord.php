<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendanceRecord extends Model
{
    use HasUuids;

    protected $fillable = ['user_id','work_date','clocked_in_at','clocked_out_at','status','late_minutes','worked_minutes','overtime_minutes','clock_in_latitude','clock_in_longitude','clock_out_latitude','clock_out_longitude','notes'];
    protected $casts = ['work_date'=>'date','clocked_in_at'=>'datetime','clocked_out_at'=>'datetime','late_minutes'=>'integer','worked_minutes'=>'integer','overtime_minutes'=>'integer','clock_in_latitude'=>'float','clock_in_longitude'=>'float','clock_out_latitude'=>'float','clock_out_longitude'=>'float'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
