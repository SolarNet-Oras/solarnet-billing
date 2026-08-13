<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomerLocationEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_id', 'location_capture_request_id', 'onu_reference', 'source', 'action',
        'latitude', 'longitude', 'accuracy_meters', 'captured_by_user_id',
    ];
}
