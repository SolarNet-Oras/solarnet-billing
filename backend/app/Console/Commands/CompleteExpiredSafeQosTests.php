<?php

namespace App\Console\Commands;

use App\Services\RouterQosService;
use Illuminate\Console\Command;

class CompleteExpiredSafeQosTests extends Command
{
    protected $signature = 'qos:complete-safe-tests';

    protected $description = 'Verify expired Safe QoS tests and automatically restore a customer queue if verification fails';

    public function handle(RouterQosService $qos): int
    {
        $result = $qos->completeExpiredSafeTests();
        $this->info("Checked {$result['checked']} Safe QoS test(s); passed {$result['passed']}; rolled back {$result['rolled_back']}.");
        foreach ($result['errors'] as $error) $this->warn("{$error['deployment_id']}: {$error['message']}");

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
