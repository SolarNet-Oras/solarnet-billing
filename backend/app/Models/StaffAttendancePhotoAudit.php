<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class StaffAttendancePhotoAudit extends Model
{
    use HasUuids;

    protected $fillable = ['attendance_record_id', 'actor_id', 'event', 'photo_type', 'ip_address'];
}
