<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmployeeDevice extends Model
{
    use HasUuids;
    protected $fillable = ['enrollment_id','name','employee_name','platform','os_version','agent_version','device_fingerprint_hash','token_hash','consent_accepted','consent_accepted_at','last_seen_at','last_ip_hash','security_posture','posture_checked_at','status','revoked_at','revoked_by'];
    protected $hidden = ['token_hash','device_fingerprint_hash','last_ip_hash'];
    protected $casts = ['consent_accepted'=>'boolean','consent_accepted_at'=>'datetime','last_seen_at'=>'datetime','posture_checked_at'=>'datetime','security_posture'=>'array','revoked_at'=>'datetime'];
}
