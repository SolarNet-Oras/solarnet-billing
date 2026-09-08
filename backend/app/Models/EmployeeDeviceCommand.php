<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class EmployeeDeviceCommand extends Model { use HasUuids; protected $fillable=['device_id','requested_by','command','message','reason','status','delivered_at','responded_at','result_message']; protected $casts=['delivered_at'=>'datetime','responded_at'=>'datetime']; }
