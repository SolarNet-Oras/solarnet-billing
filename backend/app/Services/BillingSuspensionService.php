<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\Invoice;
use App\Models\Router;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BillingSuspensionService
{
    public const SUSPENDED_ADDRESS_LIST = 'SUSPENDED_CUSTOMERS';

    public function __construct(
        protected QueueService $queueService,
        protected MikrotikService $mikrotikService,
    ) {
    }

    public function findCustomerByDhcpLease(?string $ipAddress, ?string $macAddress, ?string $routerId = null): ?Customer
    {
        $mac = $this->normalizeMac($macAddress);
        $ip = $ipAddress ? trim($ipAddress) : null;

        if ($mac) {
            $leaseQuery = DhcpLease::query()
                ->with('customer')
                ->whereRaw('upper(mac_address) = ?', [$mac]);

            if ($routerId) {
                $leaseQuery->where('router_id', $routerId);
            }

            $lease = $leaseQuery->latest('last_seen_at')->first();
            if ($lease?->customer) {
                return $lease->customer;
            }
        }

        if ($ip) {
            $leaseQuery = DhcpLease::query()
                ->with('customer')
                ->where('ip_address', $ip);

            if ($routerId) {
                $leaseQuery->where('router_id', $routerId);
            }

            $lease = $leaseQuery->latest('last_seen_at')->first();
            if ($lease?->customer) {
                return $lease->customer;
            }
        }

        $customerQuery = Customer::query()->with(['servicePlan', 'router']);

        if ($mac) {
            $customer = (clone $customerQuery)->whereRaw('upper(mac_address) = ?', [$mac])->first();
            if ($customer) {
                return $customer;
            }
        }

        if ($ip) {
            $customer = (clone $customerQuery)->where('ip_address', $ip)->first();
            if ($customer) {
                return $customer;
            }
        }

        return null;
    }

    public function syncExpiredCustomers(): array
    {
        $graceDays = (int) Setting::get('billing.auto_suspend_days', 15);
        $cutoff = now()->subDays($graceDays)->startOfDay();

        $customers = Customer::query()
            ->with(['servicePlan', 'router'])
            ->where(function ($query) use ($cutoff) {
                $query->where('status', 'suspended')
                    ->orWhereExists(function ($invoiceQuery) use ($cutoff) {
                        $invoiceQuery->selectRaw('1')
                            ->from('invoices')
                            ->whereColumn('invoices.customer_id', 'customers.id')
                            ->where('invoices.balance', '>', 0)
                            ->where('invoices.due_date', '<', $cutoff)
                            ->whereIn('invoices.status', ['sent', 'partial', 'overdue']);
                    });
            })
            ->get();

        $summary = [
            'cutoff' => $cutoff->toDateString(),
            'grace_days' => $graceDays,
            'evaluated' => $customers->count(),
            'suspended' => 0,
            'restored' => 0,
            'errors' => [],
        ];

        foreach ($customers as $customer) {
            try {
                $billingState = $this->billingState($customer);

                if ($billingState['should_suspend']) {
                    $this->suspendCustomer($customer, $billingState);
                    $summary['suspended']++;
                    continue;
                }

                if ($customer->status === 'suspended' && $billingState['outstanding_balance'] <= 0) {
                    $this->restoreCustomer($customer, 'payment_confirmed');
                    $summary['restored']++;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = [
                    'customer_id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    public function syncCustomerMikrotikStatus(Customer $customer, bool $force = false): array
    {
        $customer->loadMissing(['servicePlan', 'router']);
        $billingState = $this->billingState($customer);

        if ($billingState['should_suspend']) {
            return $this->suspendCustomer($customer, $billingState, $force);
        }

        return $this->restoreCustomer($customer, 'billing_current', $force);
    }

    public function suspendCustomer(Customer $customer, array $billingState = [], bool $force = false): array
    {
        $customer->loadMissing(['servicePlan', 'router']);
        $billingState = $billingState ?: $this->billingState($customer);
        $router = $customer->router;

        $customer->forceFill([
            'status' => 'suspended',
            'queue_sync_status' => 'pending',
        ])->saveQuietly();

        $queueResult = $this->queueService->syncCustomerQueue($customer);
        $addressResult = $this->syncSuspendedAddressList($customer, true, $router);

        $customer->forceFill([
            'queue_sync_status' => $queueResult['success'] ? 'synced' : 'failed',
            'queue_synced' => (bool) ($queueResult['success'] ?? false),
            'queue_last_synced_at' => now(),
        ])->saveQuietly();

        Log::info('Customer suspended for billing', [
            'customer_id' => $customer->id,
            'account_number' => $customer->account_number,
            'outstanding_balance' => $billingState['outstanding_balance'],
            'router_id' => $customer->router_id,
            'queue_result' => $queueResult['success'] ?? false,
            'address_result' => $addressResult['success'] ?? false,
            'force' => $force,
        ]);

        return [
            'success' => ($queueResult['success'] ?? false) || ($addressResult['success'] ?? false),
            'action' => 'suspend',
            'customer_id' => $customer->id,
            'billing_state' => $billingState,
            'queue' => $queueResult,
            'address_list' => $addressResult,
        ];
    }

    public function restoreCustomer(Customer $customer, string $reason = 'payment_current', bool $force = false): array
    {
        $customer->loadMissing(['servicePlan', 'router']);
        $router = $customer->router;

        $customer->forceFill([
            'status' => 'active',
            'queue_sync_status' => 'pending',
        ])->saveQuietly();

        $queueResult = $this->queueService->syncCustomerQueue($customer);
        $addressResult = $this->syncSuspendedAddressList($customer, false, $router);

        $customer->forceFill([
            'queue_sync_status' => $queueResult['success'] ? 'synced' : 'failed',
            'queue_synced' => (bool) ($queueResult['success'] ?? false),
            'queue_last_synced_at' => now(),
        ])->saveQuietly();

        Log::info('Customer restored after billing sync', [
            'customer_id' => $customer->id,
            'account_number' => $customer->account_number,
            'reason' => $reason,
            'router_id' => $customer->router_id,
            'queue_result' => $queueResult['success'] ?? false,
            'address_result' => $addressResult['success'] ?? false,
            'force' => $force,
        ]);

        return [
            'success' => ($queueResult['success'] ?? false) || ($addressResult['success'] ?? false),
            'action' => 'restore',
            'customer_id' => $customer->id,
            'reason' => $reason,
            'queue' => $queueResult,
            'address_list' => $addressResult,
        ];
    }

    public function buildPaymentReminderData(Customer $customer): array
    {
        $customer->loadMissing(['servicePlan']);
        $invoice = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->first();

        $outstanding = (float) Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('balance');

        $paymentUrl = trim((string) Setting::get('network.payment_reminder_url', ''));
        if ($paymentUrl === '') {
            $paymentUrl = rtrim(config('app.url'), '/') . '/payment-required/' . $customer->id;
        }

        return [
            'customer_id' => $customer->id,
            'account_number' => $customer->account_number,
            'full_name' => $customer->full_name,
            'status' => $customer->status,
            'due_date' => $invoice?->due_date?->toDateString(),
            'balance' => $outstanding,
            'payment_url' => rtrim($paymentUrl, '/'),
            'suspended_speed_kbps' => (int) Setting::get('network.suspended_speed_kbps', 128),
            'service_plan' => $customer->servicePlan ? [
                'name' => $customer->servicePlan->name,
                'download_speed' => $customer->servicePlan->download_speed,
                'upload_speed' => $customer->servicePlan->upload_speed,
            ] : null,
        ];
    }

    protected function billingState(Customer $customer): array
    {
        $outstanding = (float) Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('balance');

        $oldestDue = Invoice::query()
            ->where('customer_id', $customer->id)
            ->where('balance', '>', 0)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->value('due_date');

        $graceDays = (int) Setting::get('billing.auto_suspend_days', 15);
        $shouldSuspend = $outstanding > 0
            && $oldestDue !== null
            && Carbon::parse($oldestDue)->startOfDay()->lt(now()->subDays($graceDays)->startOfDay());

        return [
            'outstanding_balance' => $outstanding,
            'oldest_due_date' => $oldestDue,
            'should_suspend' => $shouldSuspend,
        ];
    }

    protected function syncSuspendedAddressList(Customer $customer, bool $suspended, ?Router $router): array
    {
        if (!$router || !$customer->ip_address) {
            return [
                'success' => false,
                'message' => 'Skipped address-list sync because router or IP is missing.',
                'skipped' => true,
            ];
        }

        if ($suspended) {
            return $this->mikrotikService->addAddressList(
                $router,
                self::SUSPENDED_ADDRESS_LIST,
                $customer->ip_address,
                'Suspended customer ' . $customer->account_number
            );
        }

        return $this->mikrotikService->removeAddressList(
            $router,
            self::SUSPENDED_ADDRESS_LIST,
            $customer->ip_address
        );
    }

    protected function normalizeMac(?string $macAddress): ?string
    {
        if (!$macAddress) {
            return null;
        }

        $normalized = strtoupper(trim($macAddress));
        return $normalized === '' ? null : $normalized;
    }
}
