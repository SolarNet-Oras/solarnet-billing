<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\Invoice;
use App\Models\Router;
use App\Models\Setting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BillingSuspensionService
{
    /** Must exactly match the list created by MikrotikScriptGenerator. */
    public const SUSPENDED_ADDRESS_LIST = 'suspended_customers';
    private const LEGACY_SUSPENDED_ADDRESS_LIST = 'SUSPENDED_CUSTOMERS';

    public function __construct(
        protected QueueService $queueService,
        protected MikrotikService $mikrotikService,
        protected CustomerWebPushNotificationService $webPushNotificationService,
    ) {
    }

    public function findCustomerByDhcpLease(?string $ipAddress, ?string $macAddress, ?string $routerId = null): ?Customer
    {
        $mac = $this->normalizeMac($macAddress);
        $ip = $ipAddress ? trim($ipAddress) : null;

        if ($mac) {
            $leaseQuery = DhcpLease::query()
                ->with('customer')
                ->active()
                ->presentOnRouter()
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
                ->active()
                ->presentOnRouter()
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

        if ($routerId) {
            // Do not fall back to an identically addressed customer on a
            // different router when a caller has told us the source router.
            $customerQuery->where('router_id', $routerId);
        }

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
        if (!Setting::get('automation.auto_suspend_enabled', true)) {
            return [
                'enabled' => false,
                'evaluated' => 0,
                'suspended' => 0,
                'restored' => 0,
                'errors' => [],
                'message' => 'Automatic suspension is disabled in Settings.',
            ];
        }

        $graceDays = (int) Setting::get('billing.auto_suspend_days', 15);
        $cutoff = now(config('app.timezone', 'Asia/Manila'))->subDays($graceDays)->startOfDay();

        $customers = Customer::query()
            ->with(['servicePlan', 'router'])
            // New/pending applications must never be suspended by recurring
            // billing automation. Include existing automated restrictions so
            // they can be reconciled or restored after a qualifying payment.
            ->where(function ($query) {
                $query->where('status', 'active')
                    ->orWhere('suspension_source', 'automation');
            })
            ->where(function ($query) use ($cutoff) {
                $query->where('suspension_source', 'automation')
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
            'skipped_company_owned' => 0,
            'errors' => [],
        ];

        foreach ($customers as $customer) {
            try {
                if ($customer->hasCompanyOwnedPlan()) {
                    // A previously automated restriction is removed as soon
                    // as a customer is assigned to a Company Owned plan.
                    if ($customer->suspension_source === 'automation') {
                        $this->restoreCustomer($customer, 'company_owned_plan');
                        $summary['restored']++;
                    }
                    $summary['skipped_company_owned']++;
                    continue;
                }
                $billingState = $this->billingState($customer);

                if ($billingState['should_suspend'] && $customer->status === 'active') {
                    $this->suspendCustomer($customer, $billingState, false, 'automation');
                    $summary['suspended']++;
                    continue;
                }

                if ($customer->suspension_source === 'automation' && !$billingState['should_suspend']) {
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

        // Status chosen by an operator always wins. Automated suspensions are
        // different: they may be restored only after no invoice is still past
        // its configurable grace period.
        if (in_array($customer->status, ['suspended', 'expired'], true)
            && $customer->suspension_source !== 'automation') {
            return $this->suspendCustomer(
                $customer,
                $billingState,
                $force,
                $customer->suspension_source ?: 'manual',
                $customer->status,
            );
        }

        if ($customer->status === 'pending') {
            $queueResult = $this->queueService->syncCustomerQueue($customer);
            $addressResult = $this->syncSuspendedAddressList($customer, false, $customer->router);
            return [
                'success' => (bool) ($queueResult['success'] ?? false),
                'action' => 'sync_pending',
                'customer_id' => $customer->id,
                'queue' => $queueResult,
                'address_list' => $addressResult,
            ];
        }

        if ($billingState['should_suspend']) {
            return $this->suspendCustomer($customer, $billingState, $force, 'automation');
        }

        return $this->restoreCustomer($customer, 'billing_current', $force);
    }

    /**
     * Reconcile service access immediately after a verified payment. Unlike a
     * routine status sync, this explicitly permits a payment to restore a
     * manually or automatically suspended/expired customer after any
     * verified payment, including a partial payment. The normal automation
     * pass will re-evaluate any remaining overdue balance later.
     */
    public function reconcileAfterPayment(Customer $customer): array
    {
        $customer->loadMissing(['servicePlan', 'router']);
        $billingState = $this->billingState($customer);

        if (in_array($customer->status, ['suspended', 'expired'], true)) {
            return $this->restoreCustomer($customer, 'payment_confirmed', true);
        }

        if ($billingState['should_suspend']) {
            return $this->suspendCustomer(
                $customer,
                $billingState,
                true,
                $customer->suspension_source ?: 'automation',
                $customer->status,
            );
        }

        return $this->syncCustomerMikrotikStatus($customer, true);
    }

    public function suspendCustomer(Customer $customer, array $billingState = [], bool $force = false, string $source = 'manual', string $status = 'suspended'): array
    {
        $customer->loadMissing(['servicePlan', 'router']);
        $billingState = $billingState ?: $this->billingState($customer);
        $router = $customer->router;
        $previousStatus = $customer->status;

        $customer->forceFill([
            'status' => $status === 'expired' ? 'expired' : 'suspended',
            'suspension_source' => $source,
            'queue_sync_status' => 'pending',
        ])->saveQuietly();

        $queueResult = $this->queueService->syncCustomerQueue($customer);
        $addressResult = $this->syncSuspendedAddressList($customer, true, $router);

        $customer->forceFill([
            'queue_sync_status' => $queueResult['success'] ? 'synced' : 'failed',
            'queue_synced' => (bool) ($queueResult['success'] ?? false),
            'queue_last_synced_at' => now(),
        ])->saveQuietly();

        // Send one high-priority alert when the account enters a restricted
        // state. Reconciliation jobs must not repeatedly notify an account
        // that was already suspended.
        $pushDelivery = in_array($previousStatus, ['suspended', 'expired'], true)
            ? 'skipped_already_restricted'
            : $this->webPushNotificationService->sendSuspensionNotice($customer, $billingState);

        Log::info('Customer suspended for billing', [
            'customer_id' => $customer->id,
            'account_number' => $customer->account_number,
            'outstanding_balance' => $billingState['outstanding_balance'],
            'router_id' => $customer->router_id,
            'queue_result' => $queueResult['success'] ?? false,
            'address_result' => $addressResult['success'] ?? false,
            'push_delivery' => $pushDelivery,
            'force' => $force,
        ]);

        return [
            'success' => ($queueResult['success'] ?? false) || ($addressResult['success'] ?? false),
            'action' => 'suspend',
            'customer_id' => $customer->id,
            'billing_state' => $billingState,
            'queue' => $queueResult,
            'address_list' => $addressResult,
            'push_delivery' => $pushDelivery,
        ];
    }

    public function restoreCustomer(Customer $customer, string $reason = 'payment_current', bool $force = false): array
    {
        $customer->loadMissing(['servicePlan', 'router']);
        $router = $customer->router;
        $previousStatus = $customer->status;

        $customer->forceFill([
            'status' => 'active',
            'suspension_source' => null,
            'queue_sync_status' => 'pending',
        ])->saveQuietly();

        $queueResult = $this->queueService->syncCustomerQueue($customer);
        $addressResult = $this->syncSuspendedAddressList($customer, false, $router);

        $customer->forceFill([
            'queue_sync_status' => $queueResult['success'] ? 'synced' : 'failed',
            'queue_synced' => (bool) ($queueResult['success'] ?? false),
            'queue_last_synced_at' => now(),
        ])->saveQuietly();

        // A restoration alert is sent only when the account was actually
        // restricted. Routine active-to-active synchronizations stay silent.
        $pushDelivery = in_array($previousStatus, ['suspended', 'expired'], true)
            ? $this->webPushNotificationService->sendServiceRestored($customer)
            : 'skipped_not_restricted';

        Log::info('Customer restored after billing sync', [
            'customer_id' => $customer->id,
            'account_number' => $customer->account_number,
            'reason' => $reason,
            'router_id' => $customer->router_id,
            'queue_result' => $queueResult['success'] ?? false,
            'address_result' => $addressResult['success'] ?? false,
            'push_delivery' => $pushDelivery,
            'force' => $force,
        ]);

        return [
            'success' => ($queueResult['success'] ?? false) || ($addressResult['success'] ?? false),
            'action' => 'restore',
            'customer_id' => $customer->id,
            'reason' => $reason,
            'queue' => $queueResult,
            'address_list' => $addressResult,
            'push_delivery' => $pushDelivery,
        ];
    }

    public function buildPaymentReminderData(Customer $customer): array
    {
        $customer->loadMissing(['servicePlan']);
        if ($customer->hasCompanyOwnedPlan()) {
            return [
                'customer_id' => $customer->id,
                'account_number' => $customer->account_number,
                'full_name' => $customer->full_name,
                'status' => $customer->status,
                'due_date' => null,
                'balance' => 0.0,
                'payment_url' => null,
                'suspended_speed_kbps' => null,
                'company_owned' => true,
                'service_plan' => ['name' => $customer->servicePlan->name, 'download_speed' => $customer->servicePlan->download_speed, 'upload_speed' => $customer->servicePlan->upload_speed],
            ];
        }
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

    /**
     * Return the date-only schedule used by the existing automated suspension
     * check. This is intentionally the single source of truth for grace-period
     * notifications as well as suspension: a due date becomes eligible for
     * suspension on the day after its configured final grace day.
     *
     * @return array{outstanding_balance: float, triggering_invoice: Invoice|null, oldest_due_date: Carbon|null, grace_days: int, grace_period_start: Carbon|null, grace_period_end: Carbon|null, suspension_at: Carbon|null, should_suspend: bool, company_owned: bool}
     */
    public function gracePeriodSchedule(Customer $customer, ?CarbonInterface $at = null): array
    {
        $customer->loadMissing('servicePlan');
        $now = ($at ? Carbon::instance($at) : now(config('app.timezone', 'Asia/Manila')))
            ->setTimezone(config('app.timezone', 'Asia/Manila'))
            ->startOfDay();

        if ($customer->hasCompanyOwnedPlan()) {
            return [
                'outstanding_balance' => 0.0,
                'triggering_invoice' => null,
                'oldest_due_date' => null,
                'grace_days' => max(0, (int) Setting::get('billing.auto_suspend_days', 15)),
                'grace_period_start' => null,
                'grace_period_end' => null,
                'suspension_at' => null,
                'should_suspend' => false,
                'company_owned' => true,
            ];
        }

        $triggeringInvoice = Invoice::unpaid()
            ->where('customer_id', $customer->id)
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();
        $outstanding = round((float) Invoice::unpaid()
            ->where('customer_id', $customer->id)
            ->sum('balance'), 2);
        $graceDays = max(0, (int) Setting::get('billing.auto_suspend_days', 15));

        if (!$triggeringInvoice) {
            return [
                'outstanding_balance' => $outstanding,
                'triggering_invoice' => null,
                'oldest_due_date' => null,
                'grace_days' => $graceDays,
                'grace_period_start' => null,
                'grace_period_end' => null,
                'suspension_at' => null,
                'should_suspend' => false,
                'company_owned' => false,
            ];
        }

        $dates = self::gracePeriodDates($triggeringInvoice->due_date, $graceDays);

        return [
            'outstanding_balance' => $outstanding,
            'triggering_invoice' => $triggeringInvoice,
            'oldest_due_date' => $dates['due_date'],
            'grace_days' => $graceDays,
            'grace_period_start' => $dates['grace_period_start'],
            'grace_period_end' => $dates['grace_period_end'],
            'suspension_at' => $dates['suspension_at'],
            // This is equivalent to the historic due_date < today - grace_days
            // condition, expressed as the date on which that condition first
            // becomes true.
            'should_suspend' => $now->gte($dates['suspension_at']),
            'company_owned' => false,
        ];
    }

    /**
     * Pure date calculation matching the existing strict-less-than
     * suspension rule. It is public so notifications and tests cannot invent
     * a parallel grace-period calculation.
     *
     * @return array{due_date: Carbon, grace_period_start: Carbon, grace_period_end: Carbon, suspension_at: Carbon}
     */
    public static function gracePeriodDates(CarbonInterface $dueDate, int $graceDays): array
    {
        $due = Carbon::instance($dueDate)
            ->setTimezone(config('app.timezone', 'Asia/Manila'))
            ->startOfDay();
        $graceDays = max(0, $graceDays);

        return [
            'due_date' => $due,
            'grace_period_start' => $due->copy()->addDay(),
            'grace_period_end' => $due->copy()->addDays($graceDays),
            'suspension_at' => $due->copy()->addDays($graceDays + 1),
        ];
    }

    protected function billingState(Customer $customer): array
    {
        $schedule = $this->gracePeriodSchedule($customer);

        return [
            'outstanding_balance' => $schedule['outstanding_balance'],
            'oldest_due_date' => $schedule['oldest_due_date'],
            'oldest_unpaid_due_date' => $schedule['oldest_due_date'],
            'should_suspend' => $schedule['should_suspend'],
            'grace_days' => $schedule['grace_days'],
            'grace_period_start' => $schedule['grace_period_start'],
            'grace_period_end' => $schedule['grace_period_end'],
            'suspension_at' => $schedule['suspension_at'],
            'triggering_invoice_id' => $schedule['triggering_invoice']?->id,
            'company_owned' => $schedule['company_owned'],
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
            $result = $this->mikrotikService->addAddressList(
                $router,
                self::SUSPENDED_ADDRESS_LIST,
                $customer->ip_address,
                'Suspended customer ' . $customer->account_number
            );
            // Earlier builds used a differently-cased list name. Clean it up
            // so old entries cannot become unmanaged firewall exceptions.
            $this->mikrotikService->removeAddressList($router, self::LEGACY_SUSPENDED_ADDRESS_LIST, $customer->ip_address);
            return $result;
        }

        $result = $this->mikrotikService->removeAddressList(
            $router,
            self::SUSPENDED_ADDRESS_LIST,
            $customer->ip_address
        );
        $this->mikrotikService->removeAddressList($router, self::LEGACY_SUSPENDED_ADDRESS_LIST, $customer->ip_address);
        return $result;
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
