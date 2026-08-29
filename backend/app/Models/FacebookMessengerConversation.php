<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacebookMessengerConversation extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'facebook_page_connection_id', 'page_scoped_id', 'display_name',
        'human_handoff_required', 'marketing_opt_in_at', 'marketing_opt_in_by',
        'marketing_opt_out_at', 'last_inbound_at', 'last_outbound_at', 'last_message_at',
    ];

    protected $hidden = ['page_scoped_id'];

    protected function casts(): array
    {
        return [
            'human_handoff_required' => 'boolean',
            'marketing_opt_in_at' => 'datetime',
            'marketing_opt_out_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FacebookPageConnection::class, 'facebook_page_connection_id');
    }

    public function marketingOptInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marketing_opt_in_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(FacebookMessengerMessage::class)->orderBy('created_at');
    }

    public function canReceiveResponse(): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->greaterThanOrEqualTo(now()->subHours(24));
    }

    public function toAutomationArray(): array
    {
        return [
            'id' => $this->id,
            'connection_id' => $this->facebook_page_connection_id,
            'display_name' => $this->display_name ?: 'Messenger contact',
            'human_handoff_required' => (bool) $this->human_handoff_required,
            'marketing_opt_in_at' => $this->marketing_opt_in_at?->toIso8601String(),
            'marketing_opt_out_at' => $this->marketing_opt_out_at?->toIso8601String(),
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'last_outbound_at' => $this->last_outbound_at?->toIso8601String(),
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'within_response_window' => $this->canReceiveResponse(),
        ];
    }
}
