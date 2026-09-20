<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StaffLeaveRequest extends Model {
 use HasUuids;
 public const ANNUAL_CREDITS=10;
 protected $fillable=['user_id','start_date','end_date','credit_days','leave_type','reason','status','reviewed_by','reviewed_at','review_notes'];
 protected $casts=['start_date'=>'date','end_date'=>'date','credit_days'=>'integer','reviewed_at'=>'datetime'];
 public function user():BelongsTo{return $this->belongsTo(User::class);} public function reviewer():BelongsTo{return $this->belongsTo(User::class,'reviewed_by');}
}
