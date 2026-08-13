<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FinancialEntry extends Model
{
    use HasUuids;
    protected $fillable = ['transaction_definition_id', 'type', 'description', 'category', 'amount', 'entry_date', 'payment_method', 'effect_type', 'source_wallet', 'destination_wallet', 'reference', 'notes', 'idempotency_key', 'recorded_by'];
    protected function casts(): array { return ['amount' => 'float', 'entry_date' => 'date']; }
}
