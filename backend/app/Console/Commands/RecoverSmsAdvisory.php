<?php

namespace App\Console\Commands;

use App\Jobs\SendSmsAdvisoryRecipient;
use App\Models\SmsAdvisoryCampaign;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecoverSmsAdvisory extends Command
{
    protected $signature = 'sms:recover-advisory
                            {campaign : SMS advisory campaign UUID}
                            {--limit=0 : Maximum queued recipients to recover; 0 means all}
                            {--dry-run : Preview without dispatching}
                            {--confirm= : Must equal RECOVER SMS ADVISORY when not a dry run}';

    protected $description = 'Safely redispatch database-queued advisory recipients whose Redis jobs are missing';

    public function handle(): int
    {
        $campaign = SmsAdvisoryCampaign::find($this->argument('campaign'));
        if (! $campaign) {
            $this->error('Campaign not found.');
            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $query = $campaign->recipients()->where('status', 'queued')->oldest('created_at');
        if ($limit > 0) $query->limit($limit);
        $recipients = $query->get(['id', 'recipient_last4', 'status']);

        $this->table(['Campaign', 'Status', 'Queued records selected', 'Already sent', 'Failed', 'Skipped'], [[
            $campaign->id,
            $campaign->status,
            $recipients->count(),
            $campaign->sent_count,
            $campaign->failed_count,
            $campaign->skipped_count,
        ]]);

        if ($recipients->isEmpty()) {
            $this->warn('No queued recipient record is eligible for recovery.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Preview only. No Redis job or SMS was created.');
            return self::SUCCESS;
        }

        if ($this->option('confirm') !== 'RECOVER SMS ADVISORY') {
            $this->error('Stopped. Use --confirm="RECOVER SMS ADVISORY" only after checking that Redis has no original jobs.');
            return self::FAILURE;
        }

        $claimedIds = DB::transaction(function () use ($recipients): array {
            $ids = [];
            foreach ($recipients as $recipient) {
                $claimed = $recipient->newQuery()
                    ->whereKey($recipient->id)
                    ->where('status', 'queued')
                    ->update(['status' => 'redispatched', 'updated_at' => now()]);
                if ($claimed === 1) $ids[] = $recipient->id;
            }
            return $ids;
        });

        foreach ($claimedIds as $index => $id) {
            try {
                SendSmsAdvisoryRecipient::dispatch($id)->delay(now()->addSeconds(intdiv($index, 5)));
            } catch (\Throwable $e) {
                $campaign->recipients()->whereKey($id)->where('status', 'redispatched')->update([
                    'status' => 'queued',
                    'failure_reason' => 'Redispatch failed before provider delivery: '.$e->getMessage(),
                ]);
                $this->error("Could not redispatch recipient {$id}: {$e->getMessage()}");
            }
        }

        $campaign->update(['status' => 'processing']);
        $this->info(count($claimedIds).' missing advisory job(s) were safely claimed and redispatched. Running this command again will not select them.');

        return self::SUCCESS;
    }
}
