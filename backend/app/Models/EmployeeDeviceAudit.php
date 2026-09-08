<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class EmployeeDeviceAudit extends Model { use HasUuids; protected $fillable=['device_id','actor_id','event','metadata']; protected $casts=['metadata'=>'array']; }
