<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerAccountService;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    protected QueueService $queueService;
    protected CustomerAccountService $accountService;

    public function __construct(QueueService $queueService, CustomerAccountService $accountService)
    {
        $this->queueService = $queueService;
        $this->accountService = $accountService;
    }

    /**
     * Display a listing of customers
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
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
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customer = Customer::create($request->except(['send_welcome_email', 'sync_queue']));

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

        // Optionally push the queue to MikroTik in the background
        $queueSyncStatus = null;
        if ($request->boolean('sync_queue', false) && $customer->router_id && $customer->service_plan_id) {
            try {
                $syncResult = $this->queueService->syncCustomerQueue($customer);
                $queueSyncStatus = $syncResult['success'] ? 'synced' : ('failed: ' . ($syncResult['message'] ?? 'unknown'));
            } catch (\Throwable $e) {
                $queueSyncStatus = 'failed: ' . $e->getMessage();
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
        ], 201);
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

        $customer->update($request->all());

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
}
