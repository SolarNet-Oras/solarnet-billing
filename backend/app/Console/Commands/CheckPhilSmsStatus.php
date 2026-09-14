<?php

namespace App\Console\Commands;

use App\Services\SemaphoreSmsService;
use Illuminate\Console\Command;

class CheckPhilSmsStatus extends Command
{
    protected $signature = 'sms:semaphore-status';

    protected $description = 'Read the Semaphore account balance without sending an SMS or changing application data';

    public function handle(SemaphoreSmsService $sms): int
    {
        $result = $sms->balance();

        if ($result['status'] === 'available') {
            $this->info('Semaphore authentication: connected');
            $this->line('SMS units: ' . json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($result['status'] === 'not_configured') {
            $this->error('Semaphore is not configured. Set SMS_DRIVER=semaphore and SEMAPHORE_API_KEY in deploy/.env.');
        } else {
            $this->error('Semaphore authentication: failed');
            $this->error('Reason: ' . ($sms->lastFailureReason() ?? 'Unknown provider error.'));
        }

        return self::FAILURE;
    }
}
