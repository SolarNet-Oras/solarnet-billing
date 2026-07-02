<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CustomerPortalController extends Controller
{
    /**
     * Customer login. Two supported flows:
     *   (a) NEW: email + password  (checked against Customer.portal_password)
     *   (b) LEGACY: email + account_number  (backward compatible for customers
     *       created before portal passwords existed and who have no password set)
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'          => 'required|email',
            'password'       => 'nullable|string',
            'account_number' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $customer = Customer::where('email', $request->email)->first();

        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Invalid credentials'], 401);
        }

        $authenticated = false;

        // Password auth path (preferred)
        if ($request->filled('password')) {
            if ($customer->portal_password && Hash::check($request->password, $customer->portal_password)) {
                $authenticated = true;
            }
        }

        // Legacy account-number fallback (only if the customer has no password yet)
        if (!$authenticated && $request->filled('account_number') && !$customer->portal_password) {
            if ($customer->account_number === $request->account_number) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            return response()->json(['status' => 'error', 'message' => 'Invalid credentials'], 401);
        }

        // Generate a secure token using Laravel's password_hash as signing mechanism
        // In production, use Laravel Sanctum for proper token management
        $payload = [
            'customer_id' => $customer->id,
            'email' => $customer->email,
            'account_number' => $customer->account_number,
            'issued_at' => now()->timestamp,
            'expires_at' => now()->addDays(7)->timestamp,
        ];
        
        $signature = hash_hmac('sha256', json_encode($payload), config('app.key'));
        $token = base64_encode(json_encode([
            'payload' => $payload,
            'signature' => $signature,
        ]));

        return response()->json([
            'status' => 'success',
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'full_name' => $customer->full_name,
                    'email' => $customer->email,
                    'contact_number' => $customer->contact_number,
                    'status' => $customer->status,
                ],
                'access_token' => $token,
                'token_type' => 'bearer',
                'expires_at' => now()->addDays(7)->toIso8601String(),
            ],
        ]);
    }

    /**
     * Public self-signup for prospective customers.
     * Creates a customer with status=pending awaiting admin approval.
     * Provisions a portal password so they can log in and track their application.
     */
    public function signup(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name'       => 'required|string|max:255',
            'email'           => 'required|email|max:255|unique:customers,email',
            'contact_number'  => 'required|string|max:20',
            'address'         => 'required|string|max:1000',
            'service_plan_id' => 'nullable|exists:service_plans,id',
            'notes'           => 'nullable|string|max:2000',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Please fix the errors below and try again.',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Generate a unique pending account number
        $accountNumber = 'PENDING-' . strtoupper(bin2hex(random_bytes(4)));

        // Look up default monthly fee from the selected plan (if any)
        $monthlyFee = 0;
        if ($request->filled('service_plan_id')) {
            $plan = \App\Models\ServicePlan::find($request->service_plan_id);
            if ($plan) $monthlyFee = $plan->price;
        }

        $customer = Customer::create([
            'account_number'    => $accountNumber,
            'full_name'         => $request->full_name,
            'email'             => strtolower($request->email),
            'contact_number'    => $request->contact_number,
            'address'           => $request->address,
            'service_plan_id'   => $request->service_plan_id,
            'monthly_fee'       => $monthlyFee,
            'installation_date' => now(),
            'status'            => 'pending',
            'notes'             => $request->notes,
        ]);

        $accountService = app(\App\Services\CustomerAccountService::class);
        $plain = $accountService->provisionPortalCredentials($customer);
        $accountService->sendWelcomeEmail($customer, $plain);

        return response()->json([
            'status'  => 'success',
            'message' => 'Signup received. We\'ll be in touch shortly to confirm your installation.',
            'data'    => [
                'account_number' => $customer->account_number,
                'email'          => $customer->email,
                'password'       => $plain, // shown once on success page
                'portal_url'     => rtrim(config('app.url'), '/') . '/customer/login',
            ],
        ], 201);
    }

    /**
     * Get customer dashboard data
     */
    public function dashboard(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);

        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $totalInvoices = Invoice::where('customer_id', $customer->id)->count();
        $unpaidInvoices = Invoice::where('customer_id', $customer->id)
                                ->whereIn('status', ['sent', 'partial', 'overdue'])
                                ->count();
        $totalOutstanding = Invoice::where('customer_id', $customer->id)
                                  ->whereIn('status', ['sent', 'partial', 'overdue'])
                                  ->sum('balance');
        $lastPayment = Payment::where('customer_id', $customer->id)
                             ->latest('payment_date')
                             ->first();

        return response()->json([
            'customer' => $customer->load('servicePlan', 'router'),
            'stats' => [
                'total_invoices' => $totalInvoices,
                'unpaid_invoices' => $unpaidInvoices,
                'total_outstanding' => (float) $totalOutstanding,
                'last_payment' => $lastPayment ? [
                    'amount' => $lastPayment->amount,
                    'date' => $lastPayment->payment_date->format('Y-m-d'),
                    'method' => $lastPayment->payment_method,
                ] : null,
            ],
        ]);
    }

    /**
     * Get customer invoices
     */
    public function invoices(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);

        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $query = Invoice::with(['items', 'payments'])
                       ->where('customer_id', $customer->id);

        // Filter by status if provided
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $invoices = $query->latest('issue_date')
                         ->paginate($request->get('per_page', 10));

        return response()->json($invoices);
    }

    /**
     * Get single invoice
     */
    public function invoice(Request $request, string $id): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);

        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $invoice = Invoice::with(['items', 'payments'])
                         ->where('customer_id', $customer->id)
                         ->findOrFail($id);

        return response()->json($invoice);
    }

    /**
     * Get customer payments
     */
    public function payments(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);

        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $payments = Payment::with('invoice')
                          ->where('customer_id', $customer->id)
                          ->latest('payment_date')
                          ->paginate($request->get('per_page', 10));

        return response()->json($payments);
    }

    /**
     * Update customer profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);

        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'contact_number' => 'nullable|string|max:20',
            'address' => 'nullable|string|max:500',
            'gps_coordinates' => 'nullable|array',
            'gps_coordinates.latitude' => 'nullable|numeric',
            'gps_coordinates.longitude' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customer->update($request->only([
            'contact_number',
            'address',
            'gps_coordinates',
        ]));

        return response()->json([
            'status' => 'success',
            'message' => 'Profile updated successfully',
            'customer' => $customer->fresh(),
        ]);
    }

    /**
     * Get authenticated customer from token with signature verification
     */
    protected function getAuthenticatedCustomer(Request $request): ?Customer
    {
        $token = $request->bearerToken();

        if (!$token) {
            return null;
        }

        try {
            // Decode and verify token
            $decoded = json_decode(base64_decode($token), true);
            
            if (!$decoded || !isset($decoded['payload']) || !isset($decoded['signature'])) {
                return null;
            }

            $payload = $decoded['payload'];
            $signature = $decoded['signature'];

            // Verify signature
            $expectedSignature = hash_hmac('sha256', json_encode($payload), config('app.key'));
            
            if (!hash_equals($expectedSignature, $signature)) {
                return null;
            }

            // Check expiration
            if (isset($payload['expires_at']) && $payload['expires_at'] < now()->timestamp) {
                return null;
            }

            // Verify customer still exists and is active
            $customer = Customer::find($payload['customer_id']);
            
            if (!$customer || $customer->status === 'suspended') {
                return null;
            }

            // Additional verification: check email and account match
            if ($customer->email !== $payload['email'] || 
                $customer->account_number !== $payload['account_number']) {
                return null;
            }

            return $customer;
            
        } catch (\Exception $e) {
            \Log::warning('Customer authentication failed', [
                'error' => $e->getMessage(),
                'token' => substr($token, 0, 20) . '...',
            ]);
            return null;
        }
    }
}
