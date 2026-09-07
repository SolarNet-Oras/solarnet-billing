<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymongoOutwardTransfer extends Model
{
    use HasUuids;

    protected $fillable = ['provider_transfer_id', 'webhook_event_id', 'reference_number', 'provider_reference_number', 'recipient_name', 'recipient_institution', 'recipient_account_last4', 'rail', 'amount', 'fee', 'currency', 'status', 'failure_reason', 'financial_entry_id', 'provider_created_at', 'provider_updated_at'];
    protected function casts(): array { return ['amount' => 'float', 'fee' => 'float', 'provider_created_at' => 'datetime', 'provider_updated_at' => 'datetime']; }
}
