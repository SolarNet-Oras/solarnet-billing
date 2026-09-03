<?php

namespace App\Jobs;

use App\Models\SmsAdvisoryCampaign;
use App\Models\SmsAdvisoryRecipient;
use App\Services\PhilSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SendSmsAdvisoryRecipient implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [60];
    public int $timeout = 30;

    public function __construct(public string $recipientId) {}

    public function handle(PhilSmsService $sms): void
    {
        $claimed = SmsAdvisoryRecipient::query()
            ->whereKey($this->recipientId)
            ->where('status', 'redispatched')
            ->update(['status' => 'sending', 'updated_at' => now()]);
        if ($claimed !== 1) return;

        $recipient = SmsAdvisoryRecipient::with('campaign')->find($this->recipientId);
        if (! $recipient) return;

        $recipient->campaign()->update(['status' => 'processing']);
        try {
            $result = $sms->send($recipient->recipient, $recipient->campaign->message);
        } catch (\Throwable $e) {
            $recipient->newQuery()->whereKey($recipient->id)->where('status', 'sending')->update([
                'status' => 'redispatched',
                'failure_reason' => 'Provider call failed before a delivery result: '.$e->getMessage(),
                'updated_at' => now(),
            ]);
            throw $e;
        }
        $terminal = $result === 'sent' || str_starts_with($result, 'skipped_');

        if (! $terminal && $this->attempts() < $this->tries) {
            $recipient->newQuery()->whereKey($recipient->id)->where('status', 'sending')->update([
                'status' => 'redispatched',
                'failure_reason' => $sms->lastFailureReason() ?: 'SMS provider did not accept the advisory.',
                'updated_at' => now(),
            ]);
            throw new RuntimeException($sms->lastFailureReason() ?: 'SMS provider did not accept the advisory.');
        }

        DB::transaction(function () use ($recipient, $result, $sms): void {
            $status = $result === 'sent' ? 'sent' : (str_starts_with($result, 'skipped_') ? 'skipped' : 'failed');
            $recipient->forceFill([
                'status' => $status,
                'provider_message_id' => $sms->lastProviderMessageId(),
                'failure_reason' => $status === 'sent' ? null : ($sms->lastFailureReason() ?: $result),
                'sent_at' => $status === 'sent' ? now() : null,
            ])->save();

            $campaign = SmsAdvisoryCampaign::lockForUpdate()->findOrFail($recipient->campaign_id);
            $counts = SmsAdvisoryRecipient::where('campaign_id', $campaign->id)
                ->selectRaw("status, COUNT(*) as aggregate")
                ->groupBy('status')->pluck('aggregate', 'status');
            $terminal = (int) ($counts['sent'] ?? 0) + (int) ($counts['failed'] ?? 0) + (int) ($counts['skipped'] ?? 0);
            $pending = max(0, (int) $campaign->recipient_count - $terminal);
            $campaign->forceFill([
                'sent_count' => (int) ($counts['sent'] ?? 0),
                'failed_count' => (int) ($counts['failed'] ?? 0),
                'skipped_count' => (int) ($counts['skipped'] ?? 0),
                'status' => $pending > 0 ? 'processing' : (((int) ($counts['failed'] ?? 0)) > 0 ? 'partial' : 'completed'),
                'completed_at' => $pending > 0 ? null : now(),
            ])->save();
        });
    }

    public function failed(\Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $recipient = SmsAdvisoryRecipient::query()->lockForUpdate()->find($this->recipientId);
            if (! $recipient || in_array($recipient->status, ['sent', 'failed', 'skipped'], true)) return;

            $recipient->forceFill([
                'status' => 'failed',
                'failure_reason' => 'Queue/provider attempt failed: '.$exception->getMessage(),
            ])->save();

            $campaign = SmsAdvisoryCampaign::query()->lockForUpdate()->find($recipient->campaign_id);
            if (! $campaign) return;

            $counts = SmsAdvisoryRecipient::query()->where('campaign_id', $campaign->id)
                ->selectRaw('status, COUNT(*) as aggregate')
                ->groupBy('status')->pluck('aggregate', 'status');
            $terminal = (int) ($counts['sent'] ?? 0) + (int) ($counts['failed'] ?? 0) + (int) ($counts['skipped'] ?? 0);
            $pending = max(0, (int) $campaign->recipient_count - $terminal);
            $campaign->forceFill([
                'sent_count' => (int) ($counts['sent'] ?? 0),
                'failed_count' => (int) ($counts['failed'] ?? 0),
                'skipped_count' => (int) ($counts['skipped'] ?? 0),
                'status' => $pending > 0 ? 'processing' : 'partial',
                'completed_at' => $pending > 0 ? null : now(),
            ])->save();
        });
    }
}
