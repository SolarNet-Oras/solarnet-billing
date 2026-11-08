<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendancePhotoAudit extends Model
{
    use BelongsToTenantThroughParent, HasUuids;

    public function tenantOwnershipRelation(): string { return 'attendanceRecord'; }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(StaffAttendanceRecord::class, 'attendance_record_id');
    }

    protected $fillable = ['attendance_record_id', 'actor_id', 'event', 'photo_type', 'ip_address'];
}
