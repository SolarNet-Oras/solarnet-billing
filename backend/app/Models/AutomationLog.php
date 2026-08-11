<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AutomationLog extends Model
{
    use HasUuids;

    public const JOB_INVOICE_REMINDERS = 'invoice_reminders';
    public const JOB_AUTO_SUSPEND      = 'auto_suspend';
    public const JOB_DB_BACKUP         = 'db_backup';
    public const JOB_UPDATE_OVERDUE    = 'update_overdue';
    public const JOB_RECURRING_INVOICES = 'recurring_invoices';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_ERROR   = 'error';

    protected $fillable = [
        'job',
        'status',
        'summary',
        'duration_ms',
        'triggered_by',
        'triggered_by_user_id',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'summary'     => 'array',
        'started_at'  => 'datetime',
        'finished_at' => 'datetime',
    ];
}
