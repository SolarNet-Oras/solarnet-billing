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

    // First attempt plus one automatic retry. A provider response is recorded
    // as sent only by BillingSmsReminderService after PhilSMS accepts it.
    public int $tries = 2;
    public array $backoff = [60];
    public int $timeout = 30;

    public function __construct(public string $notificationId)
    {
    }

    public function handle(BillingSmsReminderService $reminders): void
    {
        $result = $reminders->deliver($this->notificationId);
        if (in_array($result, ['retry', 'failed'], true)) {
            throw new RuntimeException('PhilSMS did not accept the billing SMS; retrying once.');
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
