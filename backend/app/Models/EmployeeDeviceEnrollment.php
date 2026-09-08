<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class EmployeeDeviceEnrollment extends Model { use HasUuids; protected $fillable=['code_hash','created_by','expires_at','used_at']; protected $hidden=['code_hash']; protected $casts=['expires_at'=>'datetime','used_at'=>'datetime']; }
