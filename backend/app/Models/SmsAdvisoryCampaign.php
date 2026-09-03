<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsAdvisoryCampaign extends Model
{
    use HasUuids;

    protected $fillable = ['created_by', 'title', 'message', 'recipient_filter', 'status', 'recipient_count', 'sent_count', 'failed_count', 'skipped_count', 'completed_at'];
    protected $casts = ['completed_at' => 'datetime'];

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function recipients(): HasMany { return $this->hasMany(SmsAdvisoryRecipient::class, 'campaign_id'); }
}
