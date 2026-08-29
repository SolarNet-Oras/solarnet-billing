<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacebookMarketingCampaign extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'facebook_page_connection_id', 'name', 'message_text', 'status',
        'recipient_count', 'sent_count', 'failed_count', 'created_by',
        'approved_by', 'approved_at', 'completed_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FacebookPageConnection::class, 'facebook_page_connection_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FacebookMessengerMessage::class);
    }

    public function toAutomationArray(): array
    {
        return [
            'id' => $this->id,
            'connection_id' => $this->facebook_page_connection_id,
            'name' => $this->name,
            'message_text' => $this->message_text,
            'status' => $this->status,
            'recipient_count' => $this->recipient_count,
            'sent_count' => $this->sent_count,
            'failed_count' => $this->failed_count,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
