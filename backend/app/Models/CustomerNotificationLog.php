<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerNotificationLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'customer_id',
        'invoice_id',
        'payment_id',
        'subscription_id',
        'dispatch_key',
        'notification_type',
        'title',
        'route',
        'status',
        'provider_message_id',
        'sent_at',
        'delivered_at',
        'clicked_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function payment(): BelongsTo { return $this->belongsTo(Payment::class); }
    public function subscription(): BelongsTo { return $this->belongsTo(CustomerWebPushSubscription::class, 'subscription_id'); }
}
