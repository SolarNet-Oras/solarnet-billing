<?php

namespace App\Console\Commands;

use App\Services\PhilSmsService;
use Illuminate\Console\Command;

class SendPhilSmsTest extends Command
{
    protected $signature = 'sms:philsms-test
                            {phone : Philippine mobile number to receive the test}
                            {--message=SolarNet SMS configuration test : Test message to send}';

    protected $description = 'Send one explicit PhilSMS configuration test; does not change billing, customers, or MikroTik';

    public function handle(PhilSmsService $philSms): int
    {
        if (!$philSms->isConfigured()) {
            $this->error('PhilSMS is not configured. Set SMS_DRIVER=philsms, PHILSMS_API_TOKEN, and PHILSMS_SENDER_ID in deploy/.env.');

            return self::FAILURE;
        }

        $delivery = $philSms->send((string) $this->argument('phone'), (string) $this->option('message'));
        $this->line("PhilSMS delivery: {$delivery}");
        if ($delivery !== 'sent' && $philSms->lastFailureReason() !== null) {
            $this->error('Reason: ' . $philSms->lastFailureReason());
        }

        return $delivery === 'sent' ? self::SUCCESS : self::FAILURE;
    }
}
