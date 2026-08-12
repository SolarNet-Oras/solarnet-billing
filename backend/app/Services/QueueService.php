<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use App\Models\ServicePlan;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;

class QueueService
{
    protected MikrotikService $mikrotikService;

    public function __construct(MikrotikService $mikrotikService)
    {
        $this->mikrotikService = $mikrotikService;
    }

    /**
     * Sync queue for a customer
     * Creates, updates, or removes queue based on customer status
     * 
     * @param Customer $customer
     * @return array
     */
    public function syncCustomerQueue(Customer $customer): array
    {
        // Load relationships
        $customer->load(['servicePlan', 'router']);

        // Short-circuit when the router is not verified as reachable.
        // Attempting a live MikroTik API call during a synchronous HTTP request
        // (e.g. Add Client / Convert Lease) against an offline router hangs
        // the request for the full TCP timeout and, worse, aborts the
        // enclosing DB::transaction when the connection is refused.
        if (!$customer->router || in_array($customer->router->connection_status, ['offline', 'unknown', null], true)) {
            return [
                'success' => true,
                'message' => 'Skipped queue sync — router is not online',
                'skipped' => true,
            ];
        }

        // Check if customer should have a queue
        if (!$this->shouldHaveQueue($customer)) {
            return $this->removeCustomerQueue($customer);
        }

        // Every non-active account receives the restricted queue. This leaves
        // only enough traffic for the router's configured payment reminder.
        if (in_array($customer->status, ['suspended', 'expired', 'pending'], true)) {
            return $this->suspendCustomerQueue($customer);
        }

        // Customer is active, ensure proper queue exists
        return $this->ensureCustomerQueue($customer);
    }

    /**
     * Check if customer should have a queue
     * 
     * @param Customer $customer
     * @return bool
     */
    protected function shouldHaveQueue(Customer $customer): bool
    {
        // Must have service plan
        if (!$customer->service_plan_id || !$customer->servicePlan) {
            return false;
        }

        // Must have IP address
        if (!$customer->ip_address) {
            return false;
        }

        // Must have router assigned
        if (!$customer->router_id || !$customer->router) {
            return false;
        }

        // Never touch a router that has never connected or is offline —
        // otherwise a dead router hangs synchronous customer-create requests.
        if (in_array($customer->router->connection_status, ['offline', 'unknown', null], true)) {
            return false;
        }

        return true;
    }

    /**
     * Ensure customer has correct queue on router
     * 
     * @param Customer $customer
     * @return array
     */
    protected function ensureCustomerQueue(Customer $customer): array
    {
        $router = $customer->router;
        $servicePlan = $customer->servicePlan;
        $queueName = $this->getQueueName($customer);

        // Check if queue already exists
        $queues = $this->mikrotikService->getQueues($router);
        $existingQueue = null;
        
        if ($queues['success']) {
            foreach ($queues['data'] as $queue) {
                if ($queue['name'] === $queueName) {
                    $existingQueue = $queue;
                    break;
                }
            }
        }

        $queueData = $this->buildQueueData($customer, $servicePlan);

        if ($existingQueue) {
            // Update existing queue
            $result = $this->mikrotikService->updateQueue($router, $queueName, [
                'max-limit' => $queueData['max_limit'],
                'burst-limit' => $queueData['burst_limit'] ?? '',
                'burst-threshold' => $queueData['burst_threshold'] ?? '',
                'burst-time' => $queueData['burst_time'] ?? '',
                'priority' => $queueData['priority'] . '/' . $queueData['priority'],
                'comment' => $queueData['comment'],
            ]);
        } else {
            // Create new queue
            $result = $this->mikrotikService->addQueue($router, $queueData);
        }

        // Update customer queue sync status
        $customer->update([
            'queue_synced' => $result['success'],
            'queue_last_synced_at' => now(),
            'queue_sync_status' => $result['success'] ? 'success' : 'failed',
        ]);

        return $result;
    }

    /**
     * Suspend customer queue (throttle to 64kbps)
     * 
     * @param Customer $customer
     * @return array
     */
    protected function suspendCustomerQueue(Customer $customer): array
    {
        if (!$customer->router) {
            return ['success' => false, 'message' => 'No router assigned'];
        }

        $router = $customer->router;
        $queueName = $this->getQueueName($customer);

        // Check if queue exists
        $queues = $this->mikrotikService->getQueues($router);
        $queueExists = false;
        
        if ($queues['success']) {
            foreach ($queues['data'] as $queue) {
                if ($queue['name'] === $queueName) {
                    $queueExists = true;
                    break;
                }
            }
        }

        if (!$queueExists) {
            // Create a throttled queue
            $queueData = [
                'name' => $queueName,
                'target' => $customer->ip_address . '/32',
            'max_limit' => $this->suspendedLimit(), // Throttle to a configurable low speed
                'comment' => strtoupper($customer->status) . " - {$customer->full_name} - {$customer->account_number}",
            ];
            
            return $this->mikrotikService->addQueue($router, $queueData);
        } else {
            // Update existing queue to throttled speed
            return $this->mikrotikService->updateQueue($router, $queueName, [
                'max-limit' => $this->suspendedLimit(),
                'comment' => strtoupper($customer->status) . " - {$customer->full_name} - {$customer->account_number}",
            ]);
        }
    }

    /**
     * Remove customer queue from router
     * 
     * @param Customer $customer
     * @return array
     */
    protected function removeCustomerQueue(Customer $customer): array
    {
        if (!$customer->router) {
            return ['success' => true, 'message' => 'No router assigned, nothing to remove'];
        }

        $router = $customer->router;
        $queueName = $this->getQueueName($customer);

        return $this->mikrotikService->removeQueue($router, $queueName);
    }

    /**
     * Build queue data array from customer and service plan
     * 
     * @param Customer $customer
     * @param ServicePlan $servicePlan
     * @return array
     */
    protected function buildQueueData(Customer $customer, ServicePlan $servicePlan): array
    {
        $queueData = [
            'name' => $this->getQueueName($customer),
            'target' => $customer->ip_address . '/32',
            'max_limit' => $servicePlan->download_speed . 'M/' . $servicePlan->upload_speed . 'M',
            'comment' => "{$customer->full_name} - {$servicePlan->name} - {$customer->account_number}",
            'priority' => $servicePlan->priority,
        ];

        // Add burst if configured
        if ($servicePlan->burst_download && $servicePlan->burst_upload) {
            $queueData['burst_limit'] = $servicePlan->burst_download . 'M/' . $servicePlan->burst_upload . 'M';
            $queueData['burst_threshold'] = ($servicePlan->burst_threshold ?? ($servicePlan->download_speed * 0.75)) . 'M/' . 
                                             ($servicePlan->burst_threshold ?? ($servicePlan->upload_speed * 0.75)) . 'M';
            $queueData['burst_time'] = ($servicePlan->burst_time ?? 16) . 's/' . ($servicePlan->burst_time ?? 16) . 's';
        }

        return $queueData;
    }

    /**
     * Get standardized queue name for customer
     * 
     * @param Customer $customer
     * @return string
     */
    protected function getQueueName(Customer $customer): string
    {
        return 'customer-' . $customer->id;
    }

    protected function suspendedLimit(): string
    {
        $kbps = max(64, (int) Setting::get('network.suspended_speed_kbps', 128));
        return $kbps . 'k/' . $kbps . 'k';
    }

    /**
     * Bulk sync queues for multiple customers
     * 
     * @param array $customerIds
     * @return array
     */
    public function bulkSyncQueues(array $customerIds): array
    {
        $results = [
            'success' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($customerIds as $customerId) {
            $customer = Customer::find($customerId);
            if (!$customer) {
                $results['failed']++;
                $results['errors'][] = "Customer not found: {$customerId}";
                continue;
            }

            $result = $this->syncCustomerQueue($customer);
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = "{$customer->account_number}: {$result['message']}";
            }
        }

        return $results;
    }
}
