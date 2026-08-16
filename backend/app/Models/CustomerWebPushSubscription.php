<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerWebPushSubscription extends Model
{
    use HasUuids;

    /**
     * Endpoint and encryption keys are delivery credentials, not customer
     * profile data. Do not serialize this model into an API response.
     */
    protected $hidden = [
        'endpoint',
        'public_key',
        'auth_token',
        'failure_reason',
    ];

    protected $fillable = [
        'customer_id',
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
        'last_used_at',
        'last_sent_at',
        'failed_at',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'last_sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
