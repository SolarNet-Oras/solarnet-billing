<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerWebPushNotificationService;
use Illuminate\Console\Command;

class SendCustomerWebPushTest extends Command
{
    protected $signature = 'web-push:test {account : Customer account number}';

    protected $description = 'Send an opt-in browser-notification test to one customer account; does not change billing or MikroTik';

    public function handle(CustomerWebPushNotificationService $webPush): int
    {
        $customer = Customer::where('account_number', trim((string) $this->argument('account')))->first();
        if (!$customer) {
            $this->error('No customer was found for that account number.');
            return self::FAILURE;
        }

        $delivery = $webPush->sendTestNotification($customer);
        $this->line("Web Push delivery for {$customer->account_number}: {$delivery}");

        return $delivery === 'sent' ? self::SUCCESS : self::FAILURE;
    }
}
