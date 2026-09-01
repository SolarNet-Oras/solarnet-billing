<?php

namespace App\Jobs;

use App\Services\TicketCreatedSmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

class SendTicketCreatedSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [60];
    public int $timeout = 30;

    public function __construct(public string $ticketId)
    {
    }

    public function handle(TicketCreatedSmsService $notifications): void
    {
        if ($notifications->deliver($this->ticketId) === 'failed') {
            throw new RuntimeException('PhilSMS did not accept the ticket-created SMS; retrying once.');
        }
    }
}
