<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookPagePostDraft extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'facebook_page_connection_id', 'topic', 'message_text', 'status',
        'facebook_post_id', 'created_by', 'approved_by', 'approved_at',
        'published_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'message_text' => 'encrypted',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
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

    /** @return array<string, mixed> */
    public function toAutomationArray(): array
    {
        return [
            'id' => $this->id,
            'connection_id' => $this->facebook_page_connection_id,
            'topic' => $this->topic,
            'message_text' => $this->message_text,
            'status' => $this->status,
            'facebook_post_id' => $this->facebook_post_id,
            'last_error' => $this->last_error,
            'created_at' => $this->created_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
        ];
    }
}
