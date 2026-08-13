<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServicePlan extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'price',
        'description',
        'download_speed',
        'upload_speed',
        'burst_download',
        'burst_upload',
        'burst_threshold',
        'burst_time',
        'priority',
        'is_active',
    ];

    protected $casts = [
        'price' => 'float',
        'download_speed' => 'integer',
        'upload_speed' => 'integer',
        'burst_download' => 'integer',
        'burst_upload' => 'integer',
        'burst_threshold' => 'integer',
        'burst_time' => 'integer',
        'priority' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get customers using this service plan
     */
    public function customers()
    {
        return $this->hasMany(Customer::class, 'service_plan_id');
    }

    /**
     * Get formatted speed string
     */
    public function getSpeedLabelAttribute(): string
    {
        return "{$this->download_speed}Mbps / {$this->upload_speed}Mbps";
    }

    /**
     * Get formatted price
     */
    public function getFormattedPriceAttribute(): string
    {
        return '₱' . number_format($this->price, 2);
    }
}
