<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalCleanupAudit extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id', 'from_date', 'to_date', 'modules', 'summary',
        'customer_count_before', 'customer_count_after', 'customer_records_deleted',
        'status', 'ip_address', 'error',
    ];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'modules' => 'array',
            'summary' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
