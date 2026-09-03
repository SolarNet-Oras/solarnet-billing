<?php

namespace App\Console\Commands;

use App\Jobs\SendSmsAdvisoryRecipient;
use App\Models\SmsAdvisoryCampaign;
use App\Models\SmsAdvisoryRecipient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchSmsAdvisoryOutbox extends Command
{
    protected $signature = 'sms:dispatch-advisory-outbox {--limit=250 : Maximum queued recipients to claim}';

    protected $description = 'Dispatch durable queued SMS advisory recipients to Redis';

    public function handle(): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $candidateIds = SmsAdvisoryRecipient::query()
            ->where('status', 'queued')
            ->oldest('created_at')
            ->limit($limit)
            ->pluck('id');

        if ($candidateIds->isEmpty()) return self::SUCCESS;

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

        foreach ($claimedIds as $index => $id) {
            try {
                SendSmsAdvisoryRecipient::dispatch($id)->delay(now()->addSeconds(intdiv($index, 5)));
            } catch (\Throwable $e) {
                SmsAdvisoryRecipient::query()->whereKey($id)->where('status', 'redispatched')->update([
                    'status' => 'queued',
                    'failure_reason' => 'Outbox dispatch failed before provider delivery: '.$e->getMessage(),
                    'updated_at' => now(),
                ]);
                $this->error("Could not dispatch recipient {$id}: {$e->getMessage()}");
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

        $this->info(count($claimedIds).' advisory recipient(s) claimed from the durable outbox.');
        return self::SUCCESS;
    }
}
