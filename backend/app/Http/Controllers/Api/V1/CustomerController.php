<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProfileChangeRequest;
use App\Models\DhcpLease;
use App\Services\CustomerAccountService;
use App\Services\CustomerAccountReconciliationService;
use App\Services\BillingSuspensionService;
use App\Services\MikrotikService;
use App\Services\QueueService;
use App\Services\InvoiceService;
use App\Support\CustomerPortalUrl;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    protected QueueService $queueService;
    protected CustomerAccountService $accountService;
    protected MikrotikService $mikrotikService;
    protected InvoiceService $invoiceService;

    public function __construct(
        QueueService $queueService,
        CustomerAccountService $accountService,
        MikrotikService $mikrotikService,
        InvoiceService $invoiceService,
    )
    {
        $this->queueService = $queueService;
        $this->accountService = $accountService;
        $this->mikrotikService = $mikrotikService;
        $this->invoiceService = $invoiceService;
    }

    /**
     * Display a listing of customers
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $search = $request->input('search');
        $status = $request->input('status');
        
        // Pending self-service applications are handled through Tickets until
        // installation/binding approval. They must not appear as clients yet.
        $query = Customer::with(['technician:id,name', 'servicePlan:id,name'])
            ->where('status', '!=', 'pending');
        
        // Search
        if ($search) {
            $query->search($search);
        }
        
        // Filter by status
        if ($status) {
            $query->where('status', $status);
        }
        
        // Sort
        $query->orderBy('created_at', 'desc');
        
        $customers = $query->paginate($perPage);
        
        return response()->json([
            'status' => 'success',
            'data' => $customers->items(),
            'meta' => [
                'current_page' => $customers->currentPage(),
                'from' => $customers->firstItem(),
                'last_page' => $customers->lastPage(),
                'per_page' => $customers->perPage(),
                'to' => $customers->lastItem(),
                'total' => $customers->total(),
            ],
        ]);
    }

    /**
     * Store a newly created customer
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'account_number' => ['required', 'string', 'regex:/^\d{10}$/', 'unique:customers,account_number'],
            'full_name' => 'required|string|max:255',
            'address' => 'required|string',
            'gps_coordinates' => 'nullable|array',
            'gps_coordinates.latitude' => 'required_with:gps_coordinates|numeric',
            'gps_coordinates.longitude' => 'required_with:gps_coordinates|numeric',
            'contact_number' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'installation_date' => 'required|date',
            'router_id' => 'nullable|exists:routers,id',
            'service_plan_id' => 'nullable|exists:service_plans,id',
            'monthly_fee' => 'required|numeric|min:0',
            'mac_address' => 'nullable|string|max:17',
            'ip_address' => 'nullable|ip',
            'vlan' => 'nullable|string|max:10',
            'status' => 'required|in:active,suspended,expired,pending',
            'onu_information' => 'nullable|string',
            'olt_port' => 'nullable|string|max:50',
            'technician_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
            // Present only when the full customer form was opened from an
            // unregistered DHCP lease. It is never mass-assigned.
            'dhcp_lease_id' => 'nullable|uuid|exists:dhcp_leases,id',
            'send_welcome_email' => 'sometimes|boolean',
            'sync_queue' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $lease = null;

        if (!empty($validated['dhcp_lease_id'])) {
            $lease = DhcpLease::with('router')->findOrFail($validated['dhcp_lease_id']);
            if ($lease->is_matched || $lease->customer_id) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'This DHCP lease is already linked to a customer.',
                ], 422);
            }
            if (!$lease->router) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'The selected DHCP lease has no configured MikroTik router.',
                ], 422);
            }

            // Network identity comes from the synced lease, never from a
            // browser query string. A service plan is required so MikroTik
            // receives a deterministic rate limit.
            if (empty($validated['service_plan_id'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Select a service plan when registering an unregistered DHCP lease.',
                ], 422);
            }
            $validated['router_id'] = $lease->router_id;
            $validated['mac_address'] = $lease->mac_address;
            $validated['ip_address'] = $lease->ip_address;
        }

        $customer = DB::transaction(function () use ($validated, $lease) {
            $customerData = Arr::except($validated, [
                'dhcp_lease_id',
                'send_welcome_email',
                'sync_queue',
            ]);
            $customer = Customer::create($customerData);

            if ($lease) {
                $lease->update([
                    'customer_id' => $customer->id,
                    'is_matched' => true,
                ]);
            }

            return $customer;
        });

        // If an email is present, provision a portal password so they can log in
        $plainPassword = null;
        if (!empty($customer->email)) {
            $plainPassword = $this->accountService->provisionPortalCredentials($customer);
        }

        // Optionally send a welcome email (defaults to true when email is set)
        $emailSent = false;
        $shouldEmail = $request->boolean('send_welcome_email', !empty($customer->email));
        if ($plainPassword && $shouldEmail) {
            $emailSent = $this->accountService->sendWelcomeEmail($customer, $plainPassword);
        }

        // The first month is used before billing. The recurring scheduler
        // creates the first invoice only when it reaches the configured lead
        // window (normally 7 days before the next installation anniversary).
        $billingInvoice = null;

        // CustomerObserver queues the MikroTik work after the transaction.
        // Never wait for a router/VPN connection in this HTTP request.
        $queueSyncStatus = null;
        if ($request->boolean('sync_queue', false) && $customer->router_id && $customer->service_plan_id) {
            $queueSyncStatus = 'queued';
        }

        // The full-form path for an unregistered lease must produce the same
        // MikroTik state as one-click registration: static lease, customer
        // name as comment, and the selected plan's rate limit.
        $mikrotikSync = null;
        if ($lease) {
            $customer->load(['servicePlan', 'router']);
            $mikrotikSync = $this->syncLeaseToMikrotik($lease->fresh('router'), $customer);

            // Keep the local lease as a faithful mirror. If the router could
            // not be reached, its next DHCP sync will still show its actual
            // dynamic/static state instead of a falsely-successful update.
            if ($mikrotikSync['success']) {
                $lease->update([
                    'comment' => $customer->full_name,
                    'rate_limit' => $this->rateLimitFor($customer),
                    'is_dynamic' => false,
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Customer created successfully',
            'data' => $customer->load(['technician', 'servicePlan']),
            // These extras are shown once by the frontend and never returned again.
            'portal_credentials' => $plainPassword ? [
                'email'    => $customer->email,
                'password' => $plainPassword,
                'portal_url' => CustomerPortalUrl::to('/customer/login'),
                'welcome_email_sent' => $emailSent,
            ] : null,
            'queue_sync' => $queueSyncStatus,
            'mikrotik_sync' => $mikrotikSync,
            'billing_invoice' => $billingInvoice,
        ], 201);
    }

    /**
     * Display the specified customer
     */
    public function show(string $id): JsonResponse
    {
        $customer = Customer::with(['technician', 'router', 'servicePlan'])
            ->findOrFail($id);
        $suspension = app(BillingSuspensionService::class);
        $financial = app(CustomerAccountReconciliationService::class)->snapshot($customer);
        $schedule = $suspension->gracePeriodSchedule($customer);
        $restorationEligibility = app(CustomerAccountReconciliationService::class)->restorationEligibility($customer, $financial, [
            'should_suspend' => $schedule['should_suspend'],
            'outstanding_balance' => $schedule['outstanding_balance'],
            'oldest_due_date' => $schedule['oldest_due_date'],
        ]);

        // A subscriber normally has one current DHCP lease. Returning it with
        // the customer keeps the operator view useful without exposing the
        // whole lease inventory or requiring another round trip.
        $lease = DhcpLease::query()
            ->with('router:id,name')
            ->where('customer_id', $customer->id)
            ->presentOnRouter()
            ->active()
            ->orderByDesc('last_seen_at')
            ->orderByDesc('updated_at')
            ->first();
        
        return response()->json([
            'status' => 'success',
            'data' => $customer,
            'billing' => $suspension->buildPaymentReminderData($customer),
            'account_reconciliation' => [
                ...$financial,
                'service_status' => $customer->status,
                'suspension_source' => $customer->suspension_source,
                'restoration_status' => $customer->restoration_status,
                'restoration_reason' => $customer->restoration_reason,
                'restoration_last_error' => $customer->restoration_last_error,
                'restoration_eligible' => $restorationEligibility['eligible'],
                'restoration_eligibility_reason' => $restorationEligibility['reason'],
            ],
            'account_reconciliation_history' => $customer->accountReconciliations()
                ->latest()
                ->limit(20)
                ->get(['id', 'payment_id', 'invoice_id', 'correlation_id', 'action', 'financial_status', 'service_status', 'previous_service_status', 'outstanding_balance', 'restoration_eligible', 'restoration_status', 'reason', 'created_at']),
            'dhcp_lease' => $lease,
            'invoices' => $customer->invoices()->latest('issue_date')->limit(20)->get(['id', 'invoice_number', 'issue_date', 'due_date', 'total', 'paid_amount', 'balance', 'status']),
            'payments' => $customer->payments()->with(['invoice:id,invoice_number', 'paymongoCheckout:id,payment_id,payment_intent_id,paymongo_payment_id,status,reference_number'])->latest('payment_date')->limit(20)->get(['id', 'invoice_id', 'amount', 'payment_method', 'payment_date', 'reference', 'transaction_id']),
            'notification_logs' => $customer->notificationLogs()
                ->with('subscription:id,device_id,platform,browser,last_used_at,revoked_at')
                ->latest()
                ->limit(20)
                ->get(['id', 'subscription_id', 'notification_type', 'title', 'route', 'status', 'sent_at', 'delivered_at', 'clicked_at', 'failure_reason', 'created_at']),
            // The paired SMS/email rows include the authoritative balance,
            // due date, grace dates, scheduled suspension date, recipient,
            // provider ID, status, and failure details for administrator audit.
            // The matching Web Push status remains in notification_logs above.
            'final_grace_warnings' => $customer->finalGracePeriodWarnings()
                ->with('invoice:id,invoice_number')
                ->latest()
                ->limit(20)
                ->get(['id', 'invoice_id', 'notification_type', 'channel', 'recipient', 'amount', 'original_due_date', 'grace_period_start', 'grace_period_end', 'suspension_at', 'portal_url', 'provider_message_id', 'status', 'attempt_count', 'last_attempt_at', 'sent_at', 'failure_reason', 'created_at']),
            'location_events' => $customer->locationEvents()->latest()->limit(20)->get(['id', 'source', 'action', 'accuracy_meters', 'created_at']),
        ]);
    }

    /** Protected cash-signature reference, available only to administrator roles. */
    public function cashSignature(string $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);

        return response()->json([
            'has_signature' => (bool) $customer->cash_signature_reference,
            'signature' => $customer->cash_signature_reference,
            'captured_at' => $customer->cash_signature_reference_at,
        ]);
    }

    /** Remove the reference so the next client cash signature becomes the new baseline. */
    public function resetCashSignature(Request $request, string $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->update([
            'cash_signature_reference' => null,
            'cash_signature_fingerprint' => null,
            'cash_signature_reference_at' => null,
        ]);

        Log::warning('Customer cash signature reference reset', [
            'customer_id' => $customer->id,
            'reset_by' => $request->user()?->id,
        ]);

        return response()->json(['message' => 'Cash signature reference reset. The next client-signed cash payment will establish a new reference.']);
    }

    /**
     * Update the specified customer
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        
        $validator = Validator::make($request->all(), [
            'account_number' => ['sometimes', 'required', 'string', 'regex:/^\d{10}$/', 'unique:customers,account_number,' . $id],
            'full_name' => 'sometimes|required|string|max:255',
            'address' => 'sometimes|required|string',
            'gps_coordinates' => 'nullable|array',
            'gps_coordinates.latitude' => 'required_with:gps_coordinates|numeric',
            'gps_coordinates.longitude' => 'required_with:gps_coordinates|numeric',
            'contact_number' => 'sometimes|required|string|max:20',
            'email' => 'nullable|email|max:255',
            'installation_date' => 'sometimes|required|date',
            'billing_cycle_day' => 'nullable|integer|min:1|max:31',
            'router_id' => 'nullable|exists:routers,id',
            'service_plan_id' => 'nullable|exists:service_plans,id',
            'monthly_fee' => 'sometimes|required|numeric|min:0',
            'mac_address' => 'nullable|string|max:17',
            'ip_address' => 'nullable|ip',
            'vlan' => 'nullable|string|max:10',
            'status' => 'sometimes|required|in:active,suspended,expired,pending',
            'onu_information' => 'nullable|string',
            'olt_port' => 'nullable|string|max:50',
            'technician_id' => 'nullable|exists:users,id',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $hardwareBinding = null;
        $matchedLeaseId = null;

        // MAC address entry/replacement has a stricter path than a normal
        // profile edit. We accept only a complete MAC, bind it only to one
        // exact current DHCP lease, and use that lease's router/IP rather
        // than trusting browser-entered network coordinates.
        if (array_key_exists('mac_address', $validated) && trim((string) $validated['mac_address']) !== '') {
            $enteredMac = $this->normalizeMacForBinding((string) $validated['mac_address']);
            if (!$enteredMac) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Enter a complete 12-character MAC address, for example AA:BB:CC:DD:EE:FF.',
                ], 422);
            }

            $currentMac = $this->normalizeMacForBinding((string) $customer->mac_address);
            $validated['mac_address'] = $enteredMac;

            if ($enteredMac !== $currentMac) {
                $macHex = str_replace(':', '', $enteredMac);
                $alreadyAssigned = Customer::query()
                    ->where('id', '!=', $customer->id)
                    ->whereRaw("upper(replace(replace(mac_address, ':', ''), '-', '')) = ?", [$macHex])
                    ->exists();
                if ($alreadyAssigned) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This MAC address is already assigned to another customer. Verify the replacement ONU/router before saving.',
                    ], 422);
                }

                $matchingLeases = DhcpLease::query()
                    ->with('router')
                    ->presentOnRouter()
                    ->active()
                    ->whereNotNull('mac_address')
                    ->whereRaw("upper(replace(replace(mac_address, ':', ''), '-', '')) = ?", [$macHex])
                    ->get();

                if ($matchingLeases->count() > 1) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This MAC appears in more than one current DHCP lease. Select the correct router after resolving the duplicate lease before changing this client.',
                    ], 422);
                }

                $matchedLease = $matchingLeases->first();
                if ($matchedLease && $matchedLease->customer_id && $matchedLease->customer_id !== $customer->id) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'This current DHCP lease is already linked to another customer. No MAC or MikroTik change was made.',
                    ], 422);
                }

                if ($matchedLease && !$matchedLease->router) {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'The matching DHCP lease has no configured MikroTik router. No MAC change was made.',
                    ], 422);
                }

                if ($matchedLease) {
                    $customer->loadMissing('servicePlan');
                    if (!$customer->servicePlan && empty($validated['service_plan_id'])) {
                        return response()->json([
                            'status' => 'error',
                            'message' => 'Assign a service plan before binding a router MAC, so MikroTik receives the correct speed limit.',
                        ], 422);
                    }

                    // The exact DHCP lease is authoritative for the new
                    // hardware identity. This prevents an old form value
                    // from moving a queue to the wrong router/IP.
                    $validated['router_id'] = $matchedLease->router_id;
                    $validated['ip_address'] = $matchedLease->ip_address;
                    $validated['mac_binding_status'] = 'matched';
                    $matchedLeaseId = $matchedLease->id;
                    $hardwareBinding = [
                        'status' => 'matched',
                        'lease_id' => $matchedLease->id,
                        'router_id' => $matchedLease->router_id,
                        'ip_address' => $matchedLease->ip_address,
                    ];
                } else {
                    // Do not create a static lease or change a queue from an
                    // unobserved device. DHCP sync will bind it only after an
                    // exact, current, bound lease is present on a router.
                    $validated['mac_binding_status'] = 'waiting_for_match';
                    $hardwareBinding = [
                        'status' => 'waiting_for_match',
                        'lease_id' => null,
                    ];
                }
            }
        }

        $statusWasChanged = array_key_exists('status', $validated) && $validated['status'] !== $customer->status;
        $manualRestorationRequested = $statusWasChanged
            && $validated['status'] === 'active'
            && in_array($customer->status, ['suspended', 'expired'], true);
        if ($statusWasChanged) {
            // Any status picked from the operator form is intentional. Keep
            // the monthly billing scheduler from undoing it unexpectedly.
            $validated['suspension_source'] = in_array($validated['status'], ['suspended', 'expired'], true)
                ? 'manual'
                : null;
        }
        if ($manualRestorationRequested) {
            // Do not write ACTIVE first. The explicit operator request still
            // requires queue and firewall restoration confirmation.
            unset($validated['status'], $validated['suspension_source']);
        }

        // Link the matched lease and detach only its previous local customer
        // association atomically. We do not delete or alter old RouterOS
        // leases, firewall rules, pools, or VLAN configuration.
        try {
            $customer = DB::transaction(function () use ($customer, $validated, $matchedLeaseId) {
                if (!$matchedLeaseId) {
                    $customer->update($validated);
                    return $customer->fresh();
                }

                $lease = DhcpLease::query()
                    ->with('router')
                    ->lockForUpdate()
                    ->findOrFail($matchedLeaseId);

                $newMacHex = str_replace(':', '', (string) $validated['mac_address']);
                $leaseMacHex = str_replace([':', '-'], '', strtoupper((string) $lease->mac_address));
                if (!$lease->is_current || $lease->status !== 'bound' || $leaseMacHex !== $newMacHex || !$lease->router) {
                    throw new \RuntimeException('The matching DHCP lease changed before it could be bound. Refresh DHCP leases and try again.');
                }
                if ($lease->customer_id && $lease->customer_id !== $customer->id) {
                    throw new \RuntimeException('The matching DHCP lease was linked to another customer before it could be bound.');
                }

                DhcpLease::query()
                    ->where('customer_id', $customer->id)
                    ->where('id', '!=', $lease->id)
                    ->presentOnRouter()
                    ->update([
                        'customer_id' => null,
                        'is_matched' => false,
                        'match_source' => null,
                        'match_note' => 'Local customer lease link replaced after an approved router/ONU MAC change.',
                    ]);

                $customer->update($validated);
                $lease->update([
                    'customer_id' => $customer->id,
                    'is_matched' => true,
                    'match_source' => 'customer_mac_update',
                    'match_note' => 'Exact current DHCP lease bound after an approved router/ONU MAC change.',
                ]);

                return $customer->fresh();
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }

        $mikrotikSync = null;
        $queueSync = null;
        if ($matchedLeaseId) {
            $lease = DhcpLease::with('router')->findOrFail($matchedLeaseId);
            $customer->load(['servicePlan', 'router']);
            $mikrotikSync = $this->syncLeaseToMikrotik($lease, $customer);
            if ($mikrotikSync['success']) {
                $lease->update([
                    'comment' => $customer->full_name,
                    'rate_limit' => $this->rateLimitFor($customer),
                    'is_dynamic' => false,
                ]);
                $queueSync = $this->queueService->syncCustomerQueue($customer, true);
            }
        }

        if ($manualRestorationRequested) {
            app(BillingSuspensionService::class)->restoreCustomer(
                $customer->fresh(['servicePlan', 'router']),
                'manual_restore',
                true,
            );
        } elseif ($statusWasChanged) {
            $customer->load(['servicePlan', 'router']);
            app(BillingSuspensionService::class)->syncCustomerMikrotikStatus($customer, true);
        }
        $customer->refresh();

        $message = 'Customer updated successfully';
        if (($hardwareBinding['status'] ?? null) === 'waiting_for_match') {
            $message = 'Customer updated. The replacement MAC is waiting for an exact current DHCP lease; no MikroTik lease or queue was changed.';
        } elseif ($mikrotikSync && !$mikrotikSync['success']) {
            $message = 'Customer was updated, but MikroTik could not make the matched lease static: ' . ($mikrotikSync['message'] ?? 'unknown error');
        } elseif (($hardwareBinding['status'] ?? null) === 'matched') {
            $message = ($queueSync['success'] ?? false)
                ? 'Customer updated. The exact DHCP lease was made static and the customer queue now uses the replacement device IP and plan limit.'
                : 'Customer updated and the DHCP lease was made static. The queue sync still needs attention: ' . ($queueSync['message'] ?? 'unknown error');
        }

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $customer->load(['technician', 'servicePlan']),
            'mac_binding' => $hardwareBinding,
            'mikrotik_sync' => $mikrotikSync,
            'queue_sync' => $queueSync,
        ]);
    }

    /**
     * Remove the specified customer (soft delete)
     */
    public function destroy(string $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        $customer->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Customer deleted successfully',
        ]);
    }

    /**
     * Bulk soft-delete multiple customers in a single request.
     *
     * Body: { "customer_ids": ["uuid1", "uuid2", ...] }
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_ids'   => 'required|array|min:1',
            'customer_ids.*' => 'required|uuid|exists:customers,id',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $ids     = $request->input('customer_ids');
        $deleted = Customer::whereIn('id', $ids)->delete();

        return response()->json([
            'status'  => 'success',
            'message' => "Deleted {$deleted} customer(s)",
            'deleted' => $deleted,
        ]);
    }

    /**
     * Controlled setup for customers that existed before SolarNet Billing.
     *
     * This intentionally separates a customer's agreed billing due-day from
     * their historical installation date. The operation is database-only:
     * it does not write RouterOS queues, firewall rules, or DHCP records.
     */
    public function bulkSetup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_ids' => 'required|array|min:1|max:500',
            'customer_ids.*' => 'required|uuid|exists:customers,id',
            'action' => 'required|in:billing_due_date,previous_balance,discount,status',
            'due_date' => 'nullable|date',
            'update_open_invoices' => 'nullable|boolean',
            'previous_balance' => 'nullable|numeric|gt:0|max:1000000',
            'previous_balance_due_date' => 'nullable|date',
            'discount_amount' => 'nullable|numeric|gt:0|max:1000000',
            'status' => 'nullable|in:active,suspended,expired,pending',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $action = $validated['action'];
        $requiredField = match ($action) {
            'billing_due_date' => 'due_date',
            'previous_balance' => 'previous_balance',
            'discount' => 'discount_amount',
            'status' => 'status',
        };

        if (!array_key_exists($requiredField, $validated) || $validated[$requiredField] === null || $validated[$requiredField] === '') {
            return response()->json([
                'status' => 'error',
                'message' => "{$requiredField} is required for this client setup action.",
            ], 422);
        }
        if ($action === 'previous_balance' && empty($validated['previous_balance_due_date'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'previous_balance_due_date is required when recording a previous bill balance.',
            ], 422);
        }

        $customerIds = array_values(array_unique($validated['customer_ids']));
        $summary = DB::transaction(function () use ($customerIds, $validated, $action) {
            $customers = Customer::query()
                ->whereIn('id', $customerIds)
                ->with('servicePlan')
                ->lockForUpdate()
                ->get();

            $updatedCustomers = 0;
            $updatedInvoices = 0;
            $skipped = [];
            $results = [];

            foreach ($customers as $customer) {
                if ($action === 'billing_due_date') {
                    $dueDate = Carbon::parse($validated['due_date'])->startOfDay();
                    $customer->update(['billing_cycle_day' => $dueDate->day]);
                    $updatedCustomers++;

                    $openInvoicesUpdated = 0;
                    if (($validated['update_open_invoices'] ?? true) === true) {
                        $openInvoices = $customer->invoices()
                            ->unpaid()
                            ->lockForUpdate()
                            ->get();
                        foreach ($openInvoices as $invoice) {
                            $invoice->update(['due_date' => $dueDate]);
                            $openInvoicesUpdated++;
                        }
                        $updatedInvoices += $openInvoicesUpdated;
                    }

                    $results[] = [
                        'customer_id' => $customer->id,
                        'account_number' => $customer->account_number,
                        'status' => 'updated',
                        'message' => "Monthly due day set to {$dueDate->format('j')}. {$openInvoicesUpdated} open invoice(s) updated.",
                    ];
                    continue;
                }

                if ($action === 'previous_balance') {
                    if ($customer->hasCompanyOwnedPlan()) {
                        $skipped[] = "{$customer->account_number}: Company Owned plans cannot receive a previous balance invoice.";
                        continue;
                    }

                    $existing = $customer->invoices()
                        ->where('notes', 'like', 'Migrated opening balance%')
                        ->first();
                    if ($existing) {
                        $skipped[] = "{$customer->account_number}: an opening balance invoice already exists ({$existing->invoice_number}).";
                        continue;
                    }

                    $balanceDueDate = Carbon::parse($validated['previous_balance_due_date'])->startOfDay();
                    $invoice = $this->invoiceService->createMigrationOpeningBalanceInvoice(
                        $customer,
                        (float) $validated['previous_balance'],
                        0,
                        $balanceDueDate->copy()->subMonthNoOverflow(),
                        $balanceDueDate,
                    );

                    if (!$invoice) {
                        $skipped[] = "{$customer->account_number}: no opening balance invoice was created.";
                        continue;
                    }

                    $updatedInvoices++;
                    $results[] = [
                        'customer_id' => $customer->id,
                        'account_number' => $customer->account_number,
                        'status' => 'updated',
                        'message' => "Previous balance invoice {$invoice->invoice_number} created.",
                    ];
                    continue;
                }

                if ($action === 'discount') {
                    $openInvoices = $customer->invoices()
                        ->unpaid()
                        ->with('items')
                        ->lockForUpdate()
                        ->get();
                    if ($openInvoices->isEmpty()) {
                        $skipped[] = "{$customer->account_number}: no open invoice can receive a discount.";
                        continue;
                    }

                    $discounted = 0;
                    foreach ($openInvoices as $invoice) {
                        $gross = round((float) $invoice->items->sum('total'), 2);
                        $remainingDiscountRoom = max(0, $gross - (float) $invoice->paid_amount - (float) $invoice->discount);
                        $additionalDiscount = min((float) $validated['discount_amount'], $remainingDiscountRoom);
                        if ($additionalDiscount <= 0) {
                            continue;
                        }

                        $invoice->update(['discount' => round((float) $invoice->discount + $additionalDiscount, 2)]);
                        $this->invoiceService->calculateInvoiceTotals($invoice->fresh(['items']));
                        $invoice->refresh();
                        if ((float) $invoice->balance <= 0) {
                            $invoice->update(['status' => 'paid', 'paid_at' => $invoice->paid_at ?? now()]);
                        }
                        $discounted++;
                    }

                    if ($discounted === 0) {
                        $skipped[] = "{$customer->account_number}: the selected discount exceeds no remaining open balance.";
                        continue;
                    }

                    $updatedInvoices += $discounted;
                    $results[] = [
                        'customer_id' => $customer->id,
                        'account_number' => $customer->account_number,
                        'status' => 'updated',
                        'message' => "Discount added to {$discounted} open invoice(s).",
                    ];
                    continue;
                }

                // Status setup is intentionally database-only. Use the normal
                // customer suspend/restore actions when a RouterOS write is
                // required and has been reviewed by an operator.
                $status = $validated['status'];
                $customer->update([
                    'status' => $status,
                    'suspension_source' => in_array($status, ['suspended', 'expired'], true) ? 'manual' : null,
                ]);
                $updatedCustomers++;
                $results[] = [
                    'customer_id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'status' => 'updated',
                    'message' => "Account status set to {$status}. No MikroTik change was sent.",
                ];
            }

            return compact('updatedCustomers', 'updatedInvoices', 'skipped', 'results');
        });

        return response()->json([
            'status' => 'success',
            'message' => "Client setup applied to {$summary['updatedCustomers']} customer(s) and {$summary['updatedInvoices']} invoice(s).",
            'data' => $summary,
        ]);
    }

    /**
     * Get customer statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total' => Customer::count(),
            'active' => Customer::active()->count(),
            'suspended' => Customer::where('status', 'suspended')->count(),
            'expired' => Customer::where('status', 'expired')->count(),
            'pending' => Customer::where('status', 'pending')->count(),
        ];

        return response()->json($stats);
    }

    /** Admin-only list of customer portal accounts. Password hashes are never returned. */
    public function portalAccounts(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $query = Customer::query()->select([
            'id', 'account_number', 'full_name', 'email', 'contact_number', 'status',
            'portal_password', 'portal_password_set_at', 'portal_password_change_required',
        ]);
        if ($search !== '') {
            $query->search($search);
        }

        return response()->json([
            'status' => 'success',
            'data' => $query->orderBy('full_name')->get()->map(fn (Customer $customer) => [
                'id' => $customer->id,
                'account_number' => $customer->account_number,
                'full_name' => $customer->full_name,
                'email' => $customer->email,
                'contact_number' => $customer->contact_number,
                'status' => $customer->status,
                'password_status' => !$customer->portal_password
                    ? 'not_set'
                    : ($customer->portal_password_change_required ? 'temporary_change_required' : 'customer_set'),
                'password_set_at' => $customer->portal_password_set_at,
            ]),
        ]);
    }

    /** Reset a portal account to the documented temporary password. */
    public function resetPortalPassword(string $id): JsonResponse
    {
        $customer = Customer::findOrFail($id);
        if (!$customer->email) {
            return response()->json(['status' => 'error', 'message' => 'This customer has no registered email address.'], 422);
        }

        app(CustomerAccountService::class)->provisionPortalCredentials($customer);

        return response()->json([
            'status' => 'success',
            'message' => 'Portal password reset. The customer must sign in with the temporary password and choose a new one.',
        ]);
    }

    /** List client-requested name and service-plan changes for staff review. */
    public function profileChangeRequests(): JsonResponse
    {
        $requests = CustomerProfileChangeRequest::with([
            'customer:id,account_number,full_name,email,service_plan_id',
            'customer.servicePlan:id,name,price',
            'requestedServicePlan:id,name,price',
            'reviewer:id,name',
        ])->latest()->get();

        return response()->json(['status' => 'success', 'data' => $requests]);
    }

    /** Apply a client request only after an administrator explicitly approves it. */
    public function approveProfileChangeRequest(string $id): JsonResponse
    {
        $change = CustomerProfileChangeRequest::with('requestedServicePlan')->findOrFail($id);
        if ($change->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'This request has already been reviewed.'], 422);
        }

        $customer = DB::transaction(function () use ($change) {
            $customer = Customer::findOrFail($change->customer_id);
            $updates = [];
            if ($change->requested_full_name) {
                $updates['full_name'] = $change->requested_full_name;
            }
            if ($change->requested_service_plan_id && $change->requestedServicePlan) {
                $updates['service_plan_id'] = $change->requested_service_plan_id;
                $updates['monthly_fee'] = $change->requestedServicePlan->price;
            }
            if ($updates) {
                $customer->update($updates);
            }
            $change->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            return $customer->fresh(['servicePlan', 'router']);
        });

        // A plan change must take effect immediately at the router. The
        // observer also queues a retry in the background, but do the first
        // Simple Queue update synchronously so an inactive worker cannot leave
        // the client on their old bandwidth limit.
        $queueSync = $this->queueService->syncCustomerQueue($customer, true);

        return response()->json([
            'status' => $queueSync['success'] ? 'success' : 'partial',
            'message' => $queueSync['success']
                ? 'Client profile change approved and MikroTik speed limit updated.'
                : 'Client profile change was approved, but MikroTik did not accept the speed update. Use Sync with MikroTik after checking the router connection.',
            'queue_sync' => $queueSync,
        ], $queueSync['success'] ? 200 : 202);
    }

    public function rejectProfileChangeRequest(Request $request, string $id): JsonResponse
    {
        $change = CustomerProfileChangeRequest::findOrFail($id);
        if ($change->status !== 'pending') {
            return response()->json(['status' => 'error', 'message' => 'This request has already been reviewed.'], 422);
        }
        $validator = Validator::make($request->all(), ['review_notes' => 'nullable|string|max:1000']);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $change->update([
            'status' => 'rejected',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'review_notes' => $request->input('review_notes'),
        ]);

        return response()->json(['status' => 'success', 'message' => 'Client profile change request rejected.']);
    }

    /**
     * Manually sync queue for a customer
     */
    public function syncQueue(string $id): JsonResponse
    {
        try {
            $customer = Customer::with(['servicePlan', 'router'])->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Customer not found',
            ], 404);
        }

        $result = $this->queueService->syncCustomerQueue($customer, true);

        return response()->json($result);
    }

    /**
     * Suspend customer internet access.
     */
    public function suspend(string $id): JsonResponse
    {
        $customer = Customer::with(['servicePlan', 'router'])->findOrFail($id);
        $result = app(BillingSuspensionService::class)->suspendCustomer($customer, [], true, 'manual', 'suspended');

        return response()->json($result);
    }

    /**
     * Restore customer internet access.
     */
    public function restore(string $id): JsonResponse
    {
        $customer = Customer::with(['servicePlan', 'router'])->findOrFail($id);
        // This is an explicit administrator action. Payment callbacks never
        // override manual/technical holds, but an administrator may request a
        // verified RouterOS restoration from the customer record.
        $result = app(BillingSuspensionService::class)->restoreCustomer($customer, 'manual_restore', true);

        return response()->json($result);
    }

    /**
     * Reconcile customer billing and network status.
     */
    public function syncNetwork(string $id): JsonResponse
    {
        $customer = Customer::with(['servicePlan', 'router'])->findOrFail($id);
        $result = app(BillingSuspensionService::class)->syncCustomerMikrotikStatus($customer);

        return response()->json($result);
    }

    /**
     * Bulk sync queues for multiple customers
     */
    public function bulkSyncQueues(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_ids' => 'required|array',
            'customer_ids.*' => 'required|string|exists:customers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $result = $this->queueService->bulkSyncQueues($request->input('customer_ids'));

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    /** Apply the customer name and plan bandwidth to the DHCP lease. */
    protected function syncLeaseToMikrotik(DhcpLease $lease, Customer $customer): array
    {
        if (!$lease->router || !$lease->mac_address) {
            return [
                'success' => false,
                'message' => 'The DHCP lease is missing its router or MAC address.',
            ];
        }

        $rateLimit = $this->rateLimitFor($customer);
        if (!$rateLimit) {
            return [
                'success' => false,
                'message' => 'A service plan is required before a DHCP rate limit can be applied.',
            ];
        }

        return $this->mikrotikService->updateOrMakeStaticLease(
            $lease->router,
            $lease->mac_address,
            $customer->full_name,
            $rateLimit,
            $lease->ip_address,
            $lease->server ?: 'default',
        );
    }

    protected function rateLimitFor(Customer $customer): ?string
    {
        if (!$customer->servicePlan) {
            return null;
        }

        return $customer->servicePlan->download_speed . 'M/' . $customer->servicePlan->upload_speed . 'M';
    }

    /** Normalize only complete, unambiguous MAC addresses for hardware binding. */
    protected function normalizeMacForBinding(string $macAddress): ?string
    {
        $hex = strtoupper((string) preg_replace('/[^A-Fa-f0-9]/', '', $macAddress));
        if (strlen($hex) !== 12 || preg_match('/^[A-F0-9]{12}$/', $hex) !== 1) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }
}
