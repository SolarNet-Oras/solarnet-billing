<?php

namespace App\Services;

use App\Jobs\SendSmsAdvisoryRecipient;
use App\Models\SmsAdvisoryCampaign;
use App\Models\SmsAdvisoryRecipient;
use Illuminate\Support\Facades\DB;

class SmsAdvisoryOutboxService
{
    /**
     * Claim and queue only recipients that are still durably queued.
     * The atomic status transition prevents repeated clicks from duplicating SMS.
     */
    public function dispatchQueued(SmsAdvisoryCampaign $campaign, int $limit = 250): int
    {
        $candidateIds = $campaign->recipients()
            ->where('status', 'queued')
            ->oldest('created_at')
            ->limit(min(1000, max(1, $limit)))
            ->pluck('id');

        if ($candidateIds->isEmpty()) return 0;

        $claimedIds = DB::transaction(function () use ($campaign, $candidateIds): array {
            $ids = [];
            foreach ($candidateIds as $id) {
                $claimed = SmsAdvisoryRecipient::query()
                    ->whereKey($id)
                    ->where('campaign_id', $campaign->id)
                    ->where('status', 'queued')
                    ->update([
                        'status' => 'redispatched',
                        'failure_reason' => null,
                        'updated_at' => now(),
                    ]);
                if ($claimed === 1) $ids[] = $id;
            }
            return $ids;
        });

        foreach ($claimedIds as $index => $id) {
            try {
                SendSmsAdvisoryRecipient::dispatch($id)
                    ->delay(now()->addSeconds(intdiv($index, 2)));
            } catch (\Throwable $exception) {
                SmsAdvisoryRecipient::query()
                    ->whereKey($id)
                    ->where('status', 'redispatched')
                    ->update([
                        'status' => 'queued',
                        'failure_reason' => 'Queue dispatch failed before provider delivery: '.$exception->getMessage(),
                        'updated_at' => now(),
                    ]);
            }
        }

        if ($claimedIds !== []) {
            $campaign->newQuery()->whereKey($campaign->id)->update([
                'status' => 'processing',
                'updated_at' => now(),
            ]);
        }

        return count($claimedIds);
    }
}
