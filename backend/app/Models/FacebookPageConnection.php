<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FacebookPageConnection extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'page_id', 'page_name', 'page_access_token', 'token_expires_at',
        'is_active', 'last_webhook_at', 'last_error', 'connected_by',
    ];

    protected $hidden = ['page_access_token'];

    protected function casts(): array
    {
        return [
            'page_access_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'is_active' => 'boolean',
            'last_webhook_at' => 'datetime',
        ];
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(FacebookMessengerConversation::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(FacebookMarketingCampaign::class);
    }

    /** Safe connection data for the employee workspace. */
    public function toAutomationArray(): array
    {
        return [
            'id' => $this->id,
            'page_id' => $this->page_id,
            'page_name' => $this->page_name,
            'is_active' => (bool) $this->is_active,
            'last_webhook_at' => $this->last_webhook_at?->toIso8601String(),
            'last_error' => $this->last_error,
            'connected_by' => $this->relationLoaded('connectedBy') && $this->connectedBy ? [
                'id' => $this->connectedBy->id,
                'name' => $this->connectedBy->name,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
