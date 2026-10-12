<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialEntry extends Model
{
    use HasUuids;
    protected $fillable = ['transaction_definition_id', 'type', 'description', 'category', 'amount', 'cash_breakdown', 'entry_date', 'payment_method', 'effect_type', 'source_wallet', 'destination_wallet', 'external_recipient_name', 'external_recipient_institution', 'external_recipient_account_last4', 'provider_transaction_id', 'provider_reference_number', 'provider_fee', 'reference', 'notes', 'idempotency_key', 'recorded_by'];
    protected function casts(): array { return ['amount' => 'float', 'provider_fee' => 'float', 'cash_breakdown' => 'array', 'entry_date' => 'date']; }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
