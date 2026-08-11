<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DhcpLease;
use App\Services\CustomerAccountService;
use App\Services\MikrotikService;
use App\Services\QueueService;
use App\Services\InvoiceService;
use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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
        
        $query = Customer::with(['technician:id,name', 'servicePlan:id,name']);
        
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

        // A newly registered active customer is immediately part of the
        // billing ledger. If their installation-day anniversary is today (or
        // has already passed this month), create the missing monthly invoice
        // now instead of making staff wait for tonight's scheduled run.
        $billingInvoice = $this->createCurrentBillingInvoice($customer);

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
                'portal_url' => rtrim(config('app.url'), '/') . '/customer/login',
                'welcome_email_sent' => $emailSent,
            ] : null,
            'queue_sync' => $queueSyncStatus,
            'mikrotik_sync' => $mikrotikSync,
            'billing_invoice' => $billingInvoice,
        ], 201);
    }

    /** Create the current installation-anniversary invoice when one is due. */
    private function createCurrentBillingInvoice(Customer $customer): ?Invoice
    {
        if ($customer->status !== 'active' || !$customer->installation_date) {
            return null;
        }

        $today = now()->startOfDay();
        $installationDate = Carbon::parse($customer->installation_date)->startOfDay();
        if ($installationDate->isAfter($today)) {
            return null;
        }

        $dueDate = $today->copy()->setDay(min($installationDate->day, $today->daysInMonth));
        if ($dueDate->isAfter($today) || Invoice::where('customer_id', $customer->id)->whereDate('due_date', $dueDate)->exists()) {
            return null;
        }

        $customer->loadMissing('servicePlan');
        if (!$customer->servicePlan && (float) $customer->monthly_fee <= 0) {
            return null;
        }

        $invoice = $this->invoiceService->generateInvoice(
            $customer,
            $dueDate->copy()->subMonthNoOverflow(),
            $dueDate,
            [],
            $dueDate,
            $dueDate,
        );
        $this->invoiceService->markAsSent($invoice);

        return $invoice->fresh(['items']);
    }

    /**
     * Display the specified customer
     */
    public function show(string $id): JsonResponse
    {
        $customer = Customer::with(['technician', 'router', 'servicePlan'])
            ->findOrFail($id);
        
        return response()->json([
            'status' => 'success',
            'data' => $customer,
        ]);
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

        $customer->update($validator->validated());

        return response()->json([
            'status' => 'success',
            'message' => 'Customer updated successfully',
            'data' => $customer->load(['technician', 'servicePlan']),
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

        $result = $this->queueService->syncCustomerQueue($customer);

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
}
