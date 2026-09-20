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
    protected $signature = 'sms:dispatch-advisory-outbox {--limit=250 : Maximum queued recipients to claim}';

    protected $description = 'Dispatch durable queued SMS advisory recipients to Redis';

    public function handle(): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));

        // A deploy, Redis restart, or worker interruption can remove a queued
        // job after its durable recipient row was claimed. Reclaim only when
        // Redis has no ready/delayed/reserved jobs, no provider call is active,
        // and the claim has been untouched for five minutes. This prevents a
        // delayed or currently executing job from producing a duplicate SMS.
        $recovered = 0;
        if (Queue::size('default') === 0
            && ! SmsAdvisoryRecipient::query()->where('status', 'sending')->exists()) {
            $staleIds = SmsAdvisoryRecipient::query()
                ->where('status', 'redispatched')
                ->where('updated_at', '<=', now()->subMinutes(5))
                ->oldest('updated_at')
                ->limit($limit)
                ->pluck('id');

            if ($staleIds->isNotEmpty()) {
                $recovered = SmsAdvisoryRecipient::query()
                    ->whereIn('id', $staleIds)
                    ->where('status', 'redispatched')
                    ->where('updated_at', '<=', now()->subMinutes(5))
                    ->update([
                        'status' => 'queued',
                        'failure_reason' => 'Automatically recovered after the Redis queue was verified empty.',
                        'updated_at' => now(),
                    ]);
            }
        }

        $candidateIds = SmsAdvisoryRecipient::query()
            ->where('status', 'queued')
            ->oldest('created_at')
            ->limit($limit)
            ->pluck('id');

        if ($candidateIds->isEmpty()) {
            if ($recovered > 0) $this->info("{$recovered} stale advisory recipient(s) recovered; none remained claimable.");
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
