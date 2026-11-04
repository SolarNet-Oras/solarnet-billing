<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCollectionPreference extends Model
{
    use HasUuids;
    protected $fillable = ['customer_id', 'visit_allowed', 'early_collection_allowed', 'preferred_start_time', 'preferred_end_time', 'address_group_override', 'payment_arrangement', 'updated_by'];
    protected $casts = ['visit_allowed' => 'boolean', 'early_collection_allowed' => 'boolean'];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
