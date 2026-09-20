<?php

namespace App\Console\Commands;

use App\Jobs\SendSmsAdvisoryRecipient;
use App\Models\SmsAdvisoryCampaign;
use App\Models\SmsAdvisoryRecipient;
use App\Services\SemaphoreSmsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

class DispatchSmsAdvisoryOutbox extends Command
{
    protected $signature = 'sms:dispatch-advisory-outbox
                            {--limit=250 : Maximum queued recipients to claim}
                            {--campaign= : Process only this campaign UUID}
                            {--recover-claimed : Immediately reclaim abandoned redispatched rows after safety checks}';

    protected $description = 'Deliver durable queued SMS advisory recipients directly to the configured provider';

    public function handle(): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $campaignId = trim((string) $this->option('campaign'));
        $recoverClaimed = (bool) $this->option('recover-claimed');
        if ($campaignId !== '' && ! SmsAdvisoryCampaign::query()->whereKey($campaignId)->exists()) {
            $this->error('The requested SMS advisory campaign was not found.');
            return self::FAILURE;
        }

        // A deploy, Redis restart, or worker interruption can remove a queued
        // job after its durable recipient row was claimed. Reclaim only when
        // Redis has no ready/delayed/reserved jobs, no provider call is active,
        // and the claim has been untouched for five minutes. This prevents a
        // delayed or currently executing job from producing a duplicate SMS.
        $recovered = 0;
        $queueIsEmpty = Queue::size('default') === 0;
        $sendingExists = SmsAdvisoryRecipient::query()
            ->when($campaignId !== '', fn ($query) => $query->where('campaign_id', $campaignId))
            ->where('status', 'sending')->exists();
        if ($queueIsEmpty && ! $sendingExists) {
            $staleIds = SmsAdvisoryRecipient::query()
                ->when($campaignId !== '', fn ($query) => $query->where('campaign_id', $campaignId))
                ->where('status', 'redispatched')
                ->when(! $recoverClaimed, fn ($query) => $query->where('updated_at', '<=', now()->subMinutes(5)))
                ->oldest('updated_at')
                ->limit($limit)
                ->pluck('id');

            if ($staleIds->isNotEmpty()) {
                $recovered = SmsAdvisoryRecipient::query()
                    ->whereIn('id', $staleIds)
                    ->where('status', 'redispatched')
                    ->when(! $recoverClaimed, fn ($query) => $query->where('updated_at', '<=', now()->subMinutes(5)))
                    ->update([
                        'status' => 'queued',
                        'failure_reason' => $recoverClaimed
                            ? 'Explicitly recovered after verifying that the queue was empty and no provider call was active.'
                            : 'Automatically recovered after the Redis queue was verified empty.',
                        'updated_at' => now(),
                    ]);
            }
        }

        $candidateIds = SmsAdvisoryRecipient::query()
            ->when($campaignId !== '', fn ($query) => $query->where('campaign_id', $campaignId))
            ->where('status', 'queued')
            ->oldest('created_at')
            ->limit($limit)
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            if ($recovered > 0) $this->info("{$recovered} stale advisory recipient(s) recovered; none remained claimable.");
            else {
                $counts = SmsAdvisoryRecipient::query()
                    ->when($campaignId !== '', fn ($query) => $query->where('campaign_id', $campaignId))
                    ->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
                $this->warn('No queued recipient was claimable. Current states: '.($counts->isEmpty() ? 'none' : $counts->map(fn ($count, $status) => "{$status}={$count}")->implode(', ')).'.');
                if (! $queueIsEmpty) $this->warn('Redis still contains queue jobs; immediate claim recovery was refused to prevent duplicate SMS.');
                if ($sendingExists) $this->warn('A provider delivery is currently active; immediate claim recovery was refused to prevent duplicate SMS.');
                if (($counts['redispatched'] ?? 0) > 0 && ! $recoverClaimed) $this->line('Wait five minutes for automatic recovery, or use --recover-claimed after confirming no other sender process is running.');
            }
            return self::SUCCESS;
        }

        $claimedIds = DB::transaction(function () use ($candidateIds): array {
            $ids = [];
            foreach ($candidateIds as $id) {
                $claimed = SmsAdvisoryRecipient::query()
                    ->whereKey($id)
                    ->where('status', 'queued')
                    ->update(['status' => 'redispatched', 'failure_reason' => null, 'updated_at' => now()]);
                if ($claimed === 1) $ids[] = $id;
            }
            return $ids;
        });

        foreach ($claimedIds as $id) {
            try {
                // Deliver from the durable database outbox. This intentionally
                // avoids losing a job between a database claim and Redis.
                (new SendSmsAdvisoryRecipient($id))->handle(app(SemaphoreSmsService::class));
            } catch (\Throwable $e) {
                SmsAdvisoryRecipient::query()->whereKey($id)->where('status', 'redispatched')->update([
                    'failure_reason' => 'Direct outbox delivery failed and will be retried safely: '.$e->getMessage(),
                    'updated_at' => now(),
                ]);
                $this->error("Could not deliver recipient {$id}: {$e->getMessage()}");
            }
        }

        $campaignIds = SmsAdvisoryRecipient::query()
            ->whereIn('id', $claimedIds)
            ->distinct()
            ->pluck('campaign_id');
        SmsAdvisoryCampaign::query()->whereIn('id', $campaignIds)->where('status', 'queued')->update([
            'status' => 'processing',
            'updated_at' => now(),
        ]);

        $message = count($claimedIds).' advisory recipient(s) processed from the durable outbox.';
        if ($recovered > 0) $message .= " {$recovered} stale Redis claim(s) were recovered automatically.";
        $this->info($message);
        return self::SUCCESS;
    }
}
