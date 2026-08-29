<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookMessengerMessage extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'facebook_messenger_conversation_id', 'facebook_marketing_campaign_id',
        'reply_to_message_id', 'facebook_mid', 'direction', 'source', 'message_text',
        'meta_payload', 'delivery_status', 'delivery_error', 'sent_at',
    ];

    protected $hidden = ['meta_payload'];

    protected function casts(): array
    {
        return [
            // Customers sometimes volunteer account details in Messenger even
            // though the assistant asks them to use the secure portal. Keep
            // message text unreadable in a database dump without APP_KEY.
            'message_text' => 'encrypted',
            'meta_payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(FacebookMessengerConversation::class, 'facebook_messenger_conversation_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(FacebookMarketingCampaign::class, 'facebook_marketing_campaign_id');
    }

    public function toAutomationArray(): array
    {
        return [
            'id' => $this->id,
            'direction' => $this->direction,
            'source' => $this->source,
            'message_text' => $this->message_text,
            'delivery_status' => $this->delivery_status,
            'delivery_error' => $this->delivery_error,
            'sent_at' => $this->sent_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
