<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectorCustomerAssignment extends Model
{
    use HasUuids;
    protected $fillable = ['collector_id', 'customer_id', 'assigned_by', 'territory_label', 'assigned_at', 'unassigned_at'];
    protected $casts = ['assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];
    public function collector(): BelongsTo { return $this->belongsTo(User::class, 'collector_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function assigner(): BelongsTo { return $this->belongsTo(User::class, 'assigned_by'); }
}
