<?php

namespace App\Console\Commands;

use App\Services\SemaphoreSmsService;
use Illuminate\Console\Command;

class SendPhilSmsTest extends Command
{
    protected $signature = 'sms:semaphore-test
                            {phone : Philippine mobile number to receive the test}
                            {--message=SolarNet SMS configuration test : Test message to send}';

    protected $description = 'Send one explicit Semaphore configuration test; does not change billing, customers, or MikroTik';

    public function handle(SemaphoreSmsService $sms): int
    {
        if (!$sms->isConfigured()) {
            $this->error('Semaphore is not configured. Set SMS_DRIVER=semaphore and SEMAPHORE_API_KEY in deploy/.env.');

            return self::FAILURE;
        }

        $delivery = $sms->send((string) $this->argument('phone'), (string) $this->option('message'));
        $this->line("Semaphore delivery: {$delivery}");
        if ($delivery !== 'sent' && $sms->lastFailureReason() !== null) {
            $this->error('Reason: ' . $sms->lastFailureReason());
        }

        return $delivery === 'sent' ? self::SUCCESS : self::FAILURE;
    }
}
