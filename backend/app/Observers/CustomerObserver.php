<?php

namespace App\Observers;

use App\Models\Customer;
use App\Jobs\SyncCustomerQueue;
use App\Jobs\SyncRadiusSubscriber;
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
        $this->syncRadiusSubscriber($customer, 'customer_created');
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
            'mac_address',
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
            $this->syncRadiusSubscriber($customer, 'customer_updated');
        }
    }

    /**
     * Handle the Customer "deleted" event.
     */
    public function deleted(Customer $customer): void
    {
        // Soft deletion does not remove the dependent policy record. Revoke
        // the staged identity before the existing queue cleanup continues.
        try {
            app(\App\Services\RadiusSubscriberService::class)->revokeForDeletedCustomer($customer);
        } catch (\Throwable $e) {
            Log::warning('Unable to revoke RADIUS policy after customer deletion', [
                'customer_id' => $customer->id,
                'error_type' => $e::class,
            ]);
        }

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

    /** Restoring a soft-deleted customer recreates only its local policy. */
    public function restored(Customer $customer): void
    {
        $this->syncRadiusSubscriber($customer, 'customer_restored');
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

    /** Local policy staging only. It has no RouterOS/RADIUS network side effect. */
    protected function syncRadiusSubscriber(Customer $customer, string $reason): void
    {
        try {
            SyncRadiusSubscriber::dispatch($customer->id, $reason)->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('Unable to queue RADIUS subscriber policy staging', [
                'customer_id' => $customer->id,
                'error_type' => $e::class,
            ]);
        }
    }
}
