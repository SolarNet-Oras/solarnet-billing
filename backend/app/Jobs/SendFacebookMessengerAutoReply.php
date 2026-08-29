<?php

namespace App\Jobs;

use App\Models\FacebookMessengerMessage;
use App\Services\FacebookMessengerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendFacebookMessengerAutoReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [60];
    public int $timeout = 45;

    public function __construct(public string $messageId)
    {
    }

    public function handle(FacebookMessengerService $messenger): void
    {
        $message = FacebookMessengerMessage::find($this->messageId);
        if ($message) {
            $messenger->sendAutomaticReply($message);
        }
    }
}
