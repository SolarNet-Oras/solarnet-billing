<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingSmsNotification extends Model
{
    use HasUuids;

    public const TYPE_7_DAYS = 'billing_sms_7_days';

    protected $fillable = [
        'customer_id',
        'invoice_id',
        'notification_type',
        'phone_number',
        'amount',
        'due_date',
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
            'due_date' => 'date',
            'attempt_count' => 'integer',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
}
