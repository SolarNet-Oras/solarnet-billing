<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\CustomerWebPushNotificationService;
use Illuminate\Console\Command;

class SendCustomerWebPushTest extends Command
{
    protected $signature = 'web-push:test {account? : Customer account number; omit to list available accounts}';

    protected $description = 'Send an opt-in browser-notification test to one customer account; does not change billing or MikroTik';

    public function handle(CustomerWebPushNotificationService $webPush): int
    {
        $account = trim((string) $this->argument('account'));
        if ($account === '') {
            $this->table(
                ['Account number', 'Customer'],
                Customer::query()->orderBy('full_name')->get(['account_number', 'full_name'])->map(fn (Customer $customer) => [$customer->account_number, $customer->full_name])->all(),
            );
            $this->warn('Run: php artisan web-push:test ACTUAL_ACCOUNT_NUMBER');
            return self::INVALID;
        }

        $customer = Customer::where('account_number', $account)->first();
        if (!$customer) {
            $this->error("No customer was found for account number: {$account}.");
            $this->line('Run `php artisan web-push:test` without an account to list the available account numbers.');
            return self::FAILURE;
        }

        $delivery = $webPush->sendTestNotification($customer);
        $this->line("Web Push delivery for {$customer->account_number}: {$delivery}");

        return $delivery === 'sent' ? self::SUCCESS : self::FAILURE;
    }
}
