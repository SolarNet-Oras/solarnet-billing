<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffLocationHistory extends Model
{
    use HasUuids;

    protected $table = 'staff_location_history';
    protected $fillable = ['user_id', 'latitude', 'longitude', 'accuracy_meters', 'captured_at'];
    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy_meters' => 'float',
        'captured_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
