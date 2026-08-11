<?php

namespace App\Observers;

use App\Models\Customer;
use App\Jobs\SyncCustomerQueue;
use App\Services\QueueService;
use Illuminate\Support\Facades\Log;

class CustomerObserver
{
    protected QueueService $queueService;

    public function __construct(QueueService $queueService)
    {
        $this->queueService = $queueService;
    }

    /**
     * Handle the Customer "created" event.
     */
    public function created(Customer $customer): void
    {
        // Sync queue after customer is created
        $this->syncQueue($customer, 'created');
    }

    /**
     * Handle the Customer "updated" event.
     */
    public function updated(Customer $customer): void
    {
        // Check if relevant fields changed
        $relevantFields = [
            'service_plan_id',
            'router_id',
            'ip_address',
            'status',
        ];

        $changed = false;
        foreach ($relevantFields as $field) {
            if ($customer->wasChanged($field)) {
                $changed = true;
                break;
            }
        }

        if ($changed) {
            $this->syncQueue($customer, 'updated');
        }
    }

    /**
     * Handle the Customer "deleted" event.
     */
    public function deleted(Customer $customer): void
    {
        // Remove queue when customer is deleted
        try {
            $customer->load('router');
            if ($customer->router) {
                $queueName = 'customer-' . $customer->id;
                $this->queueService->removeCustomerQueue($customer);
                
                Log::info('Customer queue removed on deletion', [
                    'customer_id' => $customer->id,
                    'account_number' => $customer->account_number,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to remove queue on customer deletion', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync queue for customer
     * 
     * @param Customer $customer
     * @param string $event
     */
    protected function syncQueue(Customer $customer, string $event): void
    {
        try {
            SyncCustomerQueue::dispatch($customer->id)->afterCommit();

            Log::info("Customer queue sync queued on {$event}", [
                'customer_id' => $customer->id,
                'account_number' => $customer->account_number,
            ]);
        } catch (\Throwable $e) {
            Log::error("Unable to queue customer sync on {$event}", [
                'customer_id' => $customer->id,
                'account_number' => $customer->account_number,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
