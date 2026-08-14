<?php

namespace App\Console\Commands;

use App\Services\PaymongoService;
use Illuminate\Console\Command;

class ReconcilePaymongoCheckouts extends Command
{
    protected $signature = 'paymongo:reconcile-pending';
    protected $description = 'Reconcile recent pending PayMongo checkouts and settle confirmed invoices';

    public function handle(PaymongoService $paymongo): int
    {
        $result = $paymongo->reconcilePendingCheckouts();
        $this->info("Checked {$result['checked']} checkout(s); settled {$result['paid']}; failures {$result['failed']}.");

        return self::SUCCESS;
    }
}
