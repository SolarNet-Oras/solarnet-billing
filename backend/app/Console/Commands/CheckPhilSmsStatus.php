<?php

namespace App\Console\Commands;

use App\Services\PhilSmsService;
use Illuminate\Console\Command;

class CheckPhilSmsStatus extends Command
{
    protected $signature = 'sms:philsms-status';

    protected $description = 'Read the PhilSMS account balance without sending an SMS or changing application data';

    public function handle(PhilSmsService $philSms): int
    {
        $result = $philSms->balance();

        if ($result['status'] === 'available') {
            $this->info('PhilSMS authentication: connected');
            $this->line('SMS units: ' . json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($result['status'] === 'not_configured') {
            $this->error('PhilSMS is not configured. Set SMS_DRIVER=philsms, PHILSMS_API_TOKEN, and PHILSMS_SENDER_ID in deploy/.env.');
        } else {
            $this->error('PhilSMS authentication: failed');
            $this->error('Reason: ' . ($philSms->lastFailureReason() ?? 'Unknown provider error.'));
        }

        return self::FAILURE;
    }
}
