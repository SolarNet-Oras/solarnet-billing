<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendanceRecord extends Model
{
    use HasUuids;

    protected $fillable = ['user_id','work_date','clocked_in_at','clocked_out_at','status','late_minutes','worked_minutes','overtime_minutes','overtime_status','approved_overtime_minutes','overtime_reviewed_by','overtime_reviewed_at','overtime_review_notes','clock_in_latitude','clock_in_longitude','clock_out_latitude','clock_out_longitude','notes','verification_method','clock_in_photo_path','clock_out_photo_path','clock_in_photo_captured_at','clock_out_photo_captured_at','clock_in_ip_address','clock_out_ip_address','clock_in_device','clock_out_device'];
    protected $hidden = ['clock_in_photo_path', 'clock_out_photo_path'];
    protected $appends = ['has_clock_in_photo', 'has_clock_out_photo'];
    protected $casts = ['work_date'=>'date','clocked_in_at'=>'datetime','clocked_out_at'=>'datetime','clock_in_photo_captured_at'=>'datetime','clock_out_photo_captured_at'=>'datetime','overtime_reviewed_at'=>'datetime','late_minutes'=>'integer','worked_minutes'=>'integer','overtime_minutes'=>'integer','approved_overtime_minutes'=>'integer','clock_in_latitude'=>'float','clock_in_longitude'=>'float','clock_out_latitude'=>'float','clock_out_longitude'=>'float'];
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function overtimeReviewer(): BelongsTo { return $this->belongsTo(User::class, 'overtime_reviewed_by'); }
    public function getHasClockInPhotoAttribute(): bool { return filled($this->clock_in_photo_path); }
    public function getHasClockOutPhotoAttribute(): bool { return filled($this->clock_out_photo_path); }
}
