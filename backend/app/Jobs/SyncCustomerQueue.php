<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Services\QueueService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCustomerQueue implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(public string $customerId)
    {
    }

    /**
     * Queue changes must never delay a customer create/update HTTP request.
     */
    public function handle(QueueService $queueService): void
    {
        $customer = Customer::find($this->customerId);
        if (!$customer) {
            return;
        }

        $result = $queueService->syncCustomerQueue($customer);

        if (!$result['success']) {
            Log::warning('Background customer queue sync failed', [
                'customer_id' => $customer->id,
                'error' => $result['message'] ?? 'Unknown error',
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Background customer queue sync crashed', [
            'customer_id' => $this->customerId,
            'error' => $exception->getMessage(),
        ]);
    }
}
