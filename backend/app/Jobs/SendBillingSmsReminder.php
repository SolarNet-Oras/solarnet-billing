<?php

namespace App\Jobs;

use App\Models\BillingSmsNotification;
use App\Services\BillingSmsReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendBillingSmsReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 300];
    public int $timeout = 30;

    public function __construct(public string $notificationId)
    {
    }

    public function handle(BillingSmsReminderService $reminders): void
    {
        if ($reminders->deliver($this->notificationId) === 'retry') {
            throw new RuntimeException('Temporary PhilSMS failure; retrying the reserved 7-day billing SMS.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $notification = BillingSmsNotification::find($this->notificationId);
        if (!$notification || in_array($notification->status, ['sent', 'invalid', 'skipped'], true)) {
            return;
        }

        $notification->forceFill([
            'status' => 'failed',
            'failure_reason' => $notification->failure_reason ?: substr((string) $exception?->getMessage(), 0, 500),
        ])->save();
    }
}
