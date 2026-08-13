<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /** Never expose portal credentials or delivery metadata in API responses. */
    protected $hidden = [
        'portal_password',
        'portal_password_set_at',
        'portal_password_change_required',
        'welcome_email_sent_at',
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'account_number',
        'full_name',
        'address',
        'gps_coordinates',
        'contact_number',
        'email',
        'portal_password',
        'portal_password_set_at',
        'welcome_email_sent_at',
        'installation_date',
        'router_id',
        'service_plan_id',
        'monthly_fee',
        'mac_address',
        'ip_address',
        'vlan',
        'status',
        'onu_information',
        'olt_port',
        'technician_id',
        'notes',
        'documents',
        'queue_synced',
        'queue_last_synced_at',
        'queue_sync_status',
        'suspension_source',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gps_coordinates' => 'array',
            'documents' => 'array',
            'monthly_fee' => 'float',
            'installation_date' => 'date',
            'portal_password_set_at' => 'datetime',
            'portal_password_change_required' => 'boolean',
            'welcome_email_sent_at' => 'datetime',
            'queue_synced' => 'boolean',
            'queue_last_synced_at' => 'datetime',
        ];
    }

    /**
     * Get the technician assigned to this customer.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * Get the router for this customer.
     */
    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * Get the service plan for this customer.
     */
    public function servicePlan(): BelongsTo
    {
        return $this->belongsTo(ServicePlan::class);
    }

    public function profileChangeRequests(): HasMany
    {
        return $this->hasMany(CustomerProfileChangeRequest::class);
    }

    /**
     * Scope a query to only include active customers.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope a query to only include suspended customers.
     */
    public function scopeSuspended($query)
    {
        return $query->where('status', 'suspended');
    }

    /**
     * Scope a query to only include expired customers.
     */
    public function scopeExpired($query)
    {
        return $query->where('status', 'expired');
    }

    /**
     * Scope a query to search customers.
     */
    public function scopeSearch($query, $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('account_number', 'ilike', "%{$search}%")
              ->orWhere('full_name', 'ilike', "%{$search}%")
              ->orWhere('email', 'ilike', "%{$search}%")
              ->orWhere('contact_number', 'ilike', "%{$search}%")
              ->orWhere('mac_address', 'ilike', "%{$search}%")
              ->orWhere('ip_address', 'ilike', "%{$search}%");
        });
    }
}
