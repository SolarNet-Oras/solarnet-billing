<?php

namespace App\Jobs;

use App\Models\FacebookMarketingCampaign;
use App\Services\FacebookMessengerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendFacebookMarketingCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public string $campaignId)
    {
    }

    public function handle(FacebookMessengerService $messenger): void
    {
        $campaign = FacebookMarketingCampaign::find($this->campaignId);
        if ($campaign) {
            $messenger->sendCampaign($campaign);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $campaign = FacebookMarketingCampaign::find($this->campaignId);
        if ($campaign && $campaign->status === 'sending') {
            $campaign->forceFill([
                'status' => 'failed',
                'last_error' => 'Campaign worker failed. Review delivery records before sending another campaign.',
                'completed_at' => now(),
            ])->save();
        }
    }
}
