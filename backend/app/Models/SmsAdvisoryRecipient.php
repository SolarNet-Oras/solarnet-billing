<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenantThroughParent;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsAdvisoryRecipient extends Model
{
    use BelongsToTenantThroughParent, HasUuids;

    public function tenantOwnershipRelation(): string { return 'campaign'; }

    protected $fillable = ['campaign_id', 'customer_id', 'recipient', 'recipient_last4', 'status', 'provider_message_id', 'failure_reason', 'sent_at'];
    protected $hidden = ['recipient'];
    protected $casts = ['sent_at' => 'datetime'];

    public function campaign(): BelongsTo { return $this->belongsTo(SmsAdvisoryCampaign::class, 'campaign_id'); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
}
