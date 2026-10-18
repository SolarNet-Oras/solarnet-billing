<?php

namespace App\Console\Commands;

use App\Models\FacebookMarketingCampaign;
use App\Services\FacebookMessengerService;
use Illuminate\Console\Command;

class DispatchFacebookMarketingCampaigns extends Command
{
    protected $signature = 'facebook:dispatch-marketing-campaigns {--limit=10}';

    protected $description = 'Recover and deliver approved Facebook Messenger campaigns idempotently';

    public function handle(FacebookMessengerService $messenger): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));
        $campaigns = FacebookMarketingCampaign::query()
            ->where('status', 'sending')
            // Give the original queued job time to run before recovery. This
            // avoids racing a healthy worker while still repairing lost jobs.
            ->where('approved_at', '<=', now()->subMinutes(2))
            ->orderBy('approved_at')
            ->limit($limit)
            ->get();

        foreach ($campaigns as $campaign) {
            $messenger->sendCampaign($campaign);
        }

        $this->info($campaigns->count().' approved Facebook campaign(s) processed.');

        return self::SUCCESS;
    }
}
