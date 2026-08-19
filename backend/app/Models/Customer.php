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
        'cash_signature_reference',
        'cash_signature_fingerprint',
        'cash_signature_reference_at',
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
        'location_status',
        'location_source',
        'location_accuracy_meters',
        'location_captured_at',
        'location_confirmed_at',
        'contact_number',
        'email',
        'preferred_language',
        'portal_password',
        'portal_password_set_at',
        'welcome_email_sent_at',
        'installation_date',
        'billing_cycle_day',
        'router_id',
        'service_plan_id',
        'monthly_fee',
        'mac_address',
        'mac_binding_status',
        'ip_address',
        'vlan',
        'status',
        'onu_information',
        'olt_port',
        'technician_id',
        'notes',
        'documents',
        'cash_signature_reference',
        'cash_signature_reference_at',
        'queue_synced',
        'queue_last_synced_at',
        'queue_sync_status',
        'suspension_source',
        'restoration_status',
        'restoration_reason',
        'restoration_last_error',
        'restoration_attempted_at',
        'restoration_confirmed_at',
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
            'cash_signature_reference_at' => 'datetime',
            'monthly_fee' => 'float',
            'installation_date' => 'date',
            'billing_cycle_day' => 'integer',
            'location_accuracy_meters' => 'float',
            'location_captured_at' => 'datetime',
            'location_confirmed_at' => 'datetime',
            'portal_password_set_at' => 'datetime',
            'portal_password_change_required' => 'boolean',
            'welcome_email_sent_at' => 'datetime',
            'queue_synced' => 'boolean',
            'queue_last_synced_at' => 'datetime',
            'restoration_attempted_at' => 'datetime',
            'restoration_confirmed_at' => 'datetime',
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

    /** Company-owned connections are operational accounts, not billable subscribers. */
    public function hasCompanyOwnedPlan(): bool
    {
        $this->loadMissing('servicePlan');
        return $this->servicePlan !== null
            && str_contains(mb_strtolower((string) $this->servicePlan->name), 'company owned');
    }

    /**
     * The monthly due-day agreed with the subscriber. Older records safely
     * fall back to the installation anniversary until a migration/setup user
     * sets an explicit due date.
     */
    public function billingCycleDay(): ?int
    {
        $configuredDay = (int) ($this->billing_cycle_day ?? 0);
        if ($configuredDay >= 1 && $configuredDay <= 31) {
            return $configuredDay;
        }

        return $this->installation_date?->day;
    }

    public function profileChangeRequests(): HasMany
    {
        return $this->hasMany(CustomerProfileChangeRequest::class);
    }

    public function invoices(): HasMany { return $this->hasMany(Invoice::class); }

    public function payments(): HasMany { return $this->hasMany(Payment::class); }

    public function credits(): HasMany { return $this->hasMany(CustomerCredit::class); }

    public function locationEvents(): HasMany { return $this->hasMany(CustomerLocationEvent::class); }

    /** Browser subscriptions that this customer expressly enabled for portal alerts. */
    public function webPushSubscriptions(): HasMany
    {
        return $this->hasMany(CustomerWebPushSubscription::class);
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(CustomerNotificationLog::class);
    }

    public function troubleshootingSessions(): HasMany
    {
        return $this->hasMany(CustomerTroubleshootingSession::class);
    }

    /** One-time invoice-cycle SMS audit records; phone values stay server-side. */
    public function billingSmsNotifications(): HasMany
    {
        return $this->hasMany(BillingSmsNotification::class);
    }

    /** Final SMS/email warnings with grace-period and delivery audit details. */
    public function finalGracePeriodWarnings(): HasMany
    {
        return $this->hasMany(FinalGracePeriodWarning::class);
    }

    /** Append-only audit records for billing-to-service reconciliation. */
    public function accountReconciliations(): HasMany
    {
        return $this->hasMany(CustomerAccountReconciliation::class);
    }

    /** Staged RADIUS/IPoE policy; it does not replace the current queue state. */
    public function radiusSubscriber(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(RadiusSubscriber::class);
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
