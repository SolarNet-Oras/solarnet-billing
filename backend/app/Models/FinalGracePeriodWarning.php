<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Immutable audit and delivery record for one final billing-grace event. */
class FinalGracePeriodWarning extends Model
{
    use HasUuids;

    public const TYPE = 'FINAL_GRACE_PERIOD_WARNING';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_EMAIL = 'email';

    protected $fillable = [
        'customer_id',
        'invoice_id',
        'notification_type',
        'channel',
        'recipient',
        'amount',
        'original_due_date',
        'grace_period_start',
        'grace_period_end',
        'suspension_at',
        'portal_url',
        'provider_message_id',
        'status',
        'attempt_count',
        'last_attempt_at',
        'sent_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'original_due_date' => 'date',
            'grace_period_start' => 'date',
            'grace_period_end' => 'date',
            'suspension_at' => 'datetime',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
