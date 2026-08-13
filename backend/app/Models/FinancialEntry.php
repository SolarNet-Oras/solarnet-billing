<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class FinancialEntry extends Model
{
    use HasUuids;
    protected $fillable = ['type', 'description', 'category', 'amount', 'entry_date', 'payment_method', 'reference', 'notes', 'recorded_by'];
    protected function casts(): array { return ['amount' => 'float', 'entry_date' => 'date']; }
}
