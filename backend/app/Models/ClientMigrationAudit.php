<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class ClientMigrationAudit extends Model { use HasUuids; protected $fillable=['user_id','filename','total_rows','summary','preview']; protected $casts=['summary'=>'array','preview'=>'array']; }
