<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CollectionPlannerSetting extends Model
{
    use HasUuids;
    protected $fillable = ['geofence_meters', 'shift_start', 'shift_end', 'break_start', 'break_end', 'estimated_visit_minutes', 'critical_weight', 'due_today_weight', 'upcoming_three_day_weight', 'upcoming_seven_day_weight', 'repeat_attempt_penalty', 'verified_gps_bonus', 'updated_by'];
    protected $casts = ['geofence_meters'=>'integer','estimated_visit_minutes'=>'integer','critical_weight'=>'integer','due_today_weight'=>'integer','upcoming_three_day_weight'=>'integer','upcoming_seven_day_weight'=>'integer','repeat_attempt_penalty'=>'integer','verified_gps_bonus'=>'integer'];
}
