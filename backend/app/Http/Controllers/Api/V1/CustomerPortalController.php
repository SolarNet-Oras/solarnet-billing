<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerProfileChangeRequest;
use App\Models\CustomerWebPushSubscription;
use App\Models\DhcpLease;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymongoCheckout;
use App\Services\PaymongoService;
use App\Services\BillingSuspensionService;
use App\Services\CustomerLocationCaptureService;
use App\Services\CustomerWebPushNotificationService;
use App\Services\CustomerPortalTokenService;
use App\Support\CustomerPortalUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CustomerPortalController extends Controller
{
    public function __construct(
        protected BillingSuspensionService $billingSuspensionService,
        protected CustomerWebPushNotificationService $webPushNotificationService,
        protected CustomerPortalTokenService $customerPortalTokens,
    )
    {
    }

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

        if ($request->filled('service_plan_id') && !\App\Models\ServicePlan::whereKey($request->service_plan_id)
            ->where('is_active', true)
            ->whereRaw("LOWER(name) NOT LIKE '%company owned%'")
            ->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'The selected service plan is not available for customer signup.',
            ], 422);
        }

        // Customer email addresses are case-insensitive in normal use. Trim
        // accidental spaces too, so the portal does not reject a valid record
        // simply because staff stored mixed-case email text.
        $email = strtolower(trim($request->string('email')->toString()));
        $customer = Customer::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Invalid credentials'], 401);
        }

        $authenticated = false;

        // Password auth path (preferred)
        if ($request->filled('password')) {
            if ($customer->portal_password && Hash::check($request->password, $customer->portal_password)) {
                $authenticated = true;
            }

            // Existing records created before portal passwords use SolarNet's
            // temporary onboarding password. It is immediately hashed and the
            // customer is required to change it in the portal.
            if (!$authenticated && !$customer->portal_password && hash_equals(\App\Services\CustomerAccountService::TEMPORARY_PORTAL_PASSWORD, $request->string('password')->toString())) {
                $customer->forceFill([
                    'portal_password' => Hash::make(\App\Services\CustomerAccountService::TEMPORARY_PORTAL_PASSWORD),
                    'portal_password_set_at' => now(),
                    'portal_password_change_required' => true,
                ])->save();
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
                    'portal_password_change_required' => (bool) $customer->portal_password_change_required,
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
            'gps_coordinates' => 'nullable|array',
            'gps_coordinates.latitude' => 'required_with:gps_coordinates|numeric|between:-90,90',
            'gps_coordinates.longitude' => 'required_with:gps_coordinates|numeric|between:-180,180',
            'location_accuracy_meters' => 'nullable|numeric|min:0|max:50000',
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
            $plan = \App\Models\ServicePlan::whereKey($request->service_plan_id)
                ->where('is_active', true)
                ->whereRaw("LOWER(name) NOT LIKE '%company owned%'")
                ->first();
            if ($plan) $monthlyFee = $plan->price;
        }

        $existingCustomer = $this->findEligibleExistingCustomer($request->full_name);
        $mergedExistingCustomer = $existingCustomer !== null;

        if ($existingCustomer) {
            // A router record already exists for this subscriber. Preserve its
            // account number, service, status, invoices and network identity;
            // add only the self-service contact details that were missing.
            $customer = DB::transaction(function () use ($existingCustomer, $request) {
                $notes = trim((string) $existingCustomer->notes . "\nCustomer completed self-service portal signup.");
                $updates = [
                    'email' => strtolower($request->email),
                    'contact_number' => $this->isPlaceholder($existingCustomer->contact_number) ? $request->contact_number : $existingCustomer->contact_number,
                    'address' => $this->isPlaceholder($existingCustomer->address) ? $request->address : $existingCustomer->address,
                    'notes' => $notes,
                ];
                if ($request->filled('gps_coordinates')) {
                    $updates += [
                        'gps_coordinates' => $request->input('gps_coordinates'),
                        'location_status' => 'confirmed',
                        'location_source' => 'customer_signup',
                        'location_accuracy_meters' => $request->input('location_accuracy_meters'),
                        'location_captured_at' => now(),
                        'location_confirmed_at' => now(),
                    ];
                }
                $existingCustomer->forceFill($updates)->save();
                return $existingCustomer->fresh();
            });
        } else {
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
                'gps_coordinates'   => $request->input('gps_coordinates'),
                'location_status'   => $request->filled('gps_coordinates') ? 'confirmed' : 'not_captured',
                'location_source'   => $request->filled('gps_coordinates') ? 'customer_signup' : null,
                'location_accuracy_meters' => $request->input('location_accuracy_meters'),
                'location_captured_at' => $request->filled('gps_coordinates') ? now() : null,
                'location_confirmed_at' => $request->filled('gps_coordinates') ? now() : null,
            ]);

            app(\App\Services\TicketService::class)->createTicket([
                'customer_id' => $customer->id,
                'subject' => 'New Installation Application — approval and binding required',
                'description' => "INSTALLATION APPLICATION (not a repair ticket). New self-signup for {$customer->full_name}. A technician must complete installation and submit the router/ONU MAC address; an administrator then reviews the lease match before registration.",
                'priority' => 'medium',
                'category' => 'technical',
            ]);
        }

        $accountService = app(\App\Services\CustomerAccountService::class);
        // The submitted email is becoming the portal contact for this record;
        // issue a fresh temporary credential and force the client to choose a
        // private password after their first sign-in.
        $plain = $accountService->provisionPortalCredentials($customer);
        $accountService->sendWelcomeEmail($customer, $plain);

        // A self-service application may be linked to a DHCP lease only when
        // its MikroTik comment is an unambiguous normalized name match. This
        // intentionally does NOT activate service or modify RouterOS: staff
        // still reviews the pending account before activation.
        $leaseBinding = $mergedExistingCustomer
            ? ['linked' => true, 'message' => 'Your portal account was connected to the existing SolarNet customer record.']
            : $this->bindPendingSignupToUniqueLease($customer);

        return response()->json([
            'status'  => 'success',
            'message' => 'Signup received. We\'ll be in touch shortly to confirm your installation.',
            'data'    => [
                'account_number' => $customer->account_number,
                'email'          => $customer->email,
                'password'       => $plain, // shown once on success page
                'portal_url'     => CustomerPortalUrl::to('/customer/login'),
                'dhcp_lease_linked' => $leaseBinding['linked'],
                'dhcp_lease_message' => $leaseBinding['message'],
                'existing_customer_merged' => $mergedExistingCustomer,
            ],
        ], 201);
    }

    /**
     * Link one, and only one, current unregistered DHCP lease whose comment
     * matches the applicant name ignoring case, spaces and punctuation.
     */
    private function bindPendingSignupToUniqueLease(Customer $customer): array
    {
        $normalizedName = $this->normalizeLeaseName($customer->full_name);
        if ($normalizedName === '') {
            return ['linked' => false, 'message' => 'No DHCP lease was linked.'];
        }

        $candidates = DhcpLease::query()
            ->unmatched()
            ->active()
            ->presentOnRouter()
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->get()
            ->filter(fn (DhcpLease $lease) => $this->normalizeLeaseName((string) $lease->comment) === $normalizedName)
            ->values();

        if ($candidates->count() !== 1) {
            return [
                'linked' => false,
                'message' => $candidates->isEmpty()
                    ? 'No matching unregistered DHCP lease was found.'
                    : 'More than one matching DHCP lease was found. Staff review is required.',
            ];
        }

        $lease = $candidates->first();
        $linked = DB::transaction(function () use ($lease, $customer): bool {
            $lockedLease = DhcpLease::lockForUpdate()->find($lease->id);
            if (!$lockedLease || $lockedLease->is_matched || $lockedLease->customer_id || !$lockedLease->is_current || $lockedLease->status !== 'bound') {
                return false;
            }

            $lockedLease->update([
                'customer_id' => $customer->id,
                'is_matched' => true,
            ]);
            $customer->forceFill([
                'router_id' => $lockedLease->router_id,
                'mac_address' => $lockedLease->mac_address,
                'ip_address' => $lockedLease->ip_address,
                'notes' => trim((string) $customer->notes . "\nPending self-signup linked to DHCP lease comment: {$lockedLease->comment}"),
            ])->save();
            return true;
        });

        return [
            'linked' => $linked,
            'message' => $linked
                ? 'Your application was linked to the matching network record and is awaiting staff approval.'
                : 'The DHCP lease changed before it could be linked. Staff review is required.',
        ];
    }

    private function normalizeLeaseName(string $value): string
    {
        $value = function_exists('mb_strtolower') ? mb_strtolower(trim($value)) : strtolower(trim($value));
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
    }

    /**
     * Only merge an applicant into a single existing customer whose email is
     * empty. This avoids allowing a name-only signup to take over an existing
     * portal account that already has a registered email.
     */
    private function findEligibleExistingCustomer(string $fullName): ?Customer
    {
        $normalized = $this->normalizeLeaseName($fullName);
        if ($normalized === '') return null;

        $matches = Customer::query()->get()->filter(fn (Customer $customer) =>
            $this->normalizeLeaseName($customer->full_name) === $normalized
            && $this->isPlaceholder($customer->email)
        )->values();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    private function isPlaceholder(?string $value): bool
    {
        return blank($value) || in_array(strtolower(trim((string) $value)), ['n/a', 'na', 'to be updated'], true);
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

        if (in_array($customer->status, ['suspended', 'expired'], true)) {
            return response()->json([
                'status' => 'payment_required',
                'message' => 'Internet Service Temporarily Suspended',
                'customer' => $customer->load('servicePlan', 'router'),
                'payment_required' => $this->billingSuspensionService->buildPaymentReminderData($customer),
            ], 200);
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
                'advance_credit' => app(\App\Services\InvoiceService::class)->creditSummary($customer),
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

    /** Create a PayMongo-hosted GCash checkout for the authenticated customer's invoice. */
    public function startGcashCheckout(Request $request, string $id, PaymongoService $paymongo): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        $invoice = Invoice::where('customer_id', $customer->id)->findOrFail($id);
        try {
            $checkout = $paymongo->createGcashCheckout($invoice->load('customer'));
            $checkout['temporary_payment_access'] = app(\App\Services\MikrotikService::class)
                ->grantTemporaryPaymentCheckoutAccess($customer, (int) \App\Models\Setting::get('network.payment_checkout_access_minutes', 1440));

            return response()->json(['status' => 'success', 'data' => $checkout]);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    /** Reconcile a specific checkout with PayMongo after the customer returns. */
    public function reconcileGcashCheckout(Request $request, string $id, PaymongoService $paymongo): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        $checkout = PaymongoCheckout::where('customer_id', $customer->id)->where('checkout_session_id', $id)->firstOrFail();
        $paid = $paymongo->reconcileCheckout($checkout->checkout_session_id);
        return response()->json(['status' => 'success', 'paid' => $paid, 'checkout_status' => $checkout->fresh()->status]);
    }

    /** Re-check the most recent checkout when PayMongo returns the customer to the portal. */
    public function reconcileLatestGcashCheckout(Request $request, PaymongoService $paymongo): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);

        return response()->json(['status' => 'success', 'data' => $paymongo->reconcileLatestCustomerCheckout($customer->id)]);
    }

    /** Start a customer-owned Dynamic QR Ph Payment Intent. */
    public function startQrPhPayment(Request $request, string $id, PaymongoService $paymongo): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        $invoice = Invoice::where('customer_id', $customer->id)->findOrFail($id);
        try {
            $payment = $paymongo->createQrPhPayment($invoice->load('customer'));
            $payment['temporary_payment_access'] = app(\App\Services\MikrotikService::class)
                ->grantTemporaryPaymentCheckoutAccess($customer, (int) \App\Models\Setting::get('network.payment_checkout_access_minutes', 1440));
            return response()->json(['status' => 'success', 'data' => $payment]);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function attachQrPhPayment(Request $request, string $id, PaymongoService $paymongo): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        $data = $request->validate(['payment_method_id' => 'required|string|max:100', 'qr_image_url' => 'nullable|string|max:2000000']);
        $checkout = PaymongoCheckout::where('customer_id', $customer->id)->whereKey($id)->firstOrFail();
        try {
            return response()->json(['status' => 'success', 'data' => $paymongo->finalizeQrPhAttachment($checkout, $data['payment_method_id'], $data['qr_image_url'] ?? null)]);
        } catch (\RuntimeException $e) {
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 422);
        }
    }

    public function reconcileQrPhPayment(Request $request, string $id, PaymongoService $paymongo): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        $checkout = PaymongoCheckout::where('customer_id', $customer->id)->whereKey($id)->firstOrFail();
        $paid = $paymongo->reconcileQrPhPayment((string) $checkout->payment_intent_id);
        return response()->json(['status' => 'success', 'paid' => $paid, 'payment_status' => $checkout->fresh()->status]);
    }

    /** PayMongo webhook endpoint. The event is signed, then payment state is re-read from PayMongo. */
    public function paymongoWebhook(Request $request, PaymongoService $paymongo): JsonResponse
    {
        $secret = config('services.paymongo.webhook_secret');
        if (!$secret || !$this->validPaymongoSignature($request->getContent(), (string) $request->header('Paymongo-Signature'), $secret)) {
            return response()->json(['status' => 'error'], 401);
        }
        $payload = $request->json()->all();
        $sessionId = data_get($payload, 'data.attributes.data.id')
            ?? data_get($payload, 'data.attributes.data.attributes.checkout_session_id')
            ?? data_get($payload, 'data.attributes.data.attributes.metadata.checkout_session_id');
        $eventType = (string) (data_get($payload, 'data.attributes.type') ?? data_get($payload, 'type'));
        $eventId = (string) (data_get($payload, 'data.id') ?? data_get($payload, 'id') ?? '');
        $resource = data_get($payload, 'data.attributes.data', []);
        $paymentIntentId = data_get($resource, 'attributes.payment_intent_id')
            ?? data_get($resource, 'attributes.payment_intent.data.id')
            ?? data_get($resource, 'attributes.payment_intent.id');
        if ($sessionId && !in_array($eventType, ['payment.paid', 'payment.failed', 'qrph.expired'], true)) {
            $paymongo->reconcileCheckout((string) $sessionId);
        } elseif ($paymentIntentId && in_array($eventType, ['payment.paid', 'payment.failed', 'qrph.expired'], true)) {
            $paymongo->reconcileQrPhPayment((string) $paymentIntentId, $eventId ?: null);
        } elseif ($resource && in_array($eventType, ['payment.paid', 'payment.failed', 'qrph.expired'], true)) {
            // Some PayMongo event payloads identify the payment resource only;
            // the service resolves the intent through the stored mapping.
            $paymongo->reconcileQrPhPaymentResource((string) (data_get($resource, 'id') ?? ''), $eventId ?: null);
        }
        return response()->json(['status' => 'ok']);
    }

    private function validPaymongoSignature(string $payload, string $header, string $secret): bool
    {
        $parts = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);
            if ($key && $value) $parts[$key] = $value;
        }
        if (empty($parts['t']) || (empty($parts['te']) && empty($parts['li']))) return false;
        $expected = hash_hmac('sha256', $parts['t'] . '.' . $payload, $secret);
        return (!empty($parts['te']) && hash_equals($expected, $parts['te']))
            || (!empty($parts['li']) && hash_equals($expected, $parts['li']));
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

    /** Start one explicit, one-time GPS capture after safely binding the portal session to a current lease. */
    public function startLocationCapture(Request $request, CustomerLocationCaptureService $locationCapture): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);

        $result = $locationCapture->createRequest($customer, (string) $request->ip());
        if (!$result['eligible']) return response()->json(['status' => 'unavailable', 'message' => $result['reason']], 409);

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    /** Store an in-memory-like candidate on the short-lived request. Nothing is applied to the customer yet. */
    public function captureLocation(Request $request, CustomerLocationCaptureService $locationCapture): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        $validated = $request->validate([
            'token' => 'required|string|size:64',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'accuracy' => 'required|numeric|min:0|max:50000',
        ]);
        $result = $locationCapture->capture($customer, $validated['token'], (string) $request->ip(), (float) $validated['latitude'], (float) $validated['longitude'], (float) $validated['accuracy']);
        return response()->json(['status' => $result['success'] ? 'success' : 'error', 'data' => $result], $result['success'] ? 200 : ($result['status'] ?? 409));
    }

    /** Confirm the already captured point. This is the only action that writes customer coordinates. */
    public function confirmLocationCapture(Request $request, CustomerLocationCaptureService $locationCapture): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        $validated = $request->validate(['token' => 'required|string|size:64']);
        $result = $locationCapture->confirm($customer, $validated['token'], (string) $request->ip());
        return response()->json(['status' => $result['success'] ? 'success' : 'error', 'data' => $result], $result['success'] ? 200 : 409);
    }

    /**
     * Submit a name or service-plan change for staff approval. Passwords are
     * intentionally not included: a customer changes their own password using
     * the dedicated authenticated password endpoint.
     */
    public function submitProfileChangeRequest(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'full_name' => 'nullable|string|max:255',
            'service_plan_id' => 'nullable|uuid|exists:service_plans,id',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $requestedName = $request->filled('full_name') ? trim((string) $request->input('full_name')) : null;
        $requestedPlanId = $request->input('service_plan_id');
        if ((!$requestedName || $requestedName === $customer->full_name)
            && (!$requestedPlanId || $requestedPlanId === $customer->service_plan_id)) {
            return response()->json(['status' => 'error', 'message' => 'Choose a different name or service plan to request a change.'], 422);
        }

        if ($requestedPlanId && !\App\Models\ServicePlan::whereKey($requestedPlanId)
            ->where('is_active', true)
            ->whereRaw("LOWER(name) NOT LIKE '%company owned%'")
            ->exists()) {
            return response()->json(['status' => 'error', 'message' => 'The selected service plan is no longer available.'], 422);
        }

        $change = CustomerProfileChangeRequest::updateOrCreate(
            ['customer_id' => $customer->id, 'status' => 'pending'],
            [
                'requested_full_name' => $requestedName && $requestedName !== $customer->full_name ? $requestedName : null,
                'requested_service_plan_id' => $requestedPlanId && $requestedPlanId !== $customer->service_plan_id ? $requestedPlanId : null,
                'reviewed_by' => null,
                'reviewed_at' => null,
                'review_notes' => null,
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Your requested account changes were sent to SolarNet for approval.',
            'data' => $this->profileChangePayload($change->fresh('requestedServicePlan')),
        ], 201);
    }

    /** Return the authenticated customer’s profile-change request history. */
    public function profileChangeRequests(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        return response()->json([
            'status' => 'success',
            'data' => CustomerProfileChangeRequest::with('requestedServicePlan:id,name,price')
                ->where('customer_id', $customer->id)
                ->latest()
                ->get()
                ->map(fn (CustomerProfileChangeRequest $change) => $this->profileChangePayload($change)),
        ]);
    }

    /** Change the portal password for the currently authenticated customer. */
    public function changePassword(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'password' => 'required|string|min:10|confirmed',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        if (!$customer->portal_password || !Hash::check($request->string('current_password')->toString(), $customer->portal_password)) {
            return response()->json(['status' => 'error', 'message' => 'Your current password is incorrect.'], 422);
        }

        $customer->forceFill([
            'portal_password' => Hash::make($request->string('password')->toString()),
            'portal_password_set_at' => now(),
            'portal_password_change_required' => false,
        ])->save();

        return response()->json(['status' => 'success', 'message' => 'Password changed successfully.']);
    }

    /**
     * Returns only the VAPID public key and whether this account has opted in.
     * Push endpoints and their encryption credentials are never exposed.
     */
    public function pushNotificationStatus(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $status = $this->webPushNotificationService->statusFor($customer);

        return response()->json([
            'status' => 'success',
            'data' => [
                'enabled' => $status['enabled'],
                'subscribed' => $status['subscribed'],
                'subscription_count' => $status['subscription_count'],
                'reason' => $status['reason'],
                'public_key' => $status['enabled'] ? config('services.web_push.vapid_public_key') : null,
            ],
        ]);
    }

    /** Store an opt-in browser subscription for the authenticated customer. */
    public function subscribePushNotifications(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        if (!$this->webPushNotificationService->isConfigured()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer notifications are not configured on the server yet.',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'endpoint' => 'required|string|max:2000',
            'keys' => 'required|array',
            'keys.p256dh' => 'required|string|max:255',
            'keys.auth' => 'required|string|max:255',
            'contentEncoding' => 'nullable|in:aes128gcm,aesgcm',
            'device_id' => 'nullable|uuid',
            'platform' => 'nullable|string|max:80',
            'browser' => 'nullable|string|max:80',
        ]);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $endpoint = trim($request->string('endpoint')->toString());
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'The browser provided an invalid push subscription endpoint.',
            ], 422);
        }

        $existing = CustomerWebPushSubscription::where('endpoint', $endpoint)->first();
        if ($existing && $existing->customer_id !== $customer->id) {
            // Do not silently transfer a device subscription between accounts.
            // The other portal user must turn notifications off first.
            return response()->json([
                'status' => 'error',
                'message' => 'This device is already subscribed to another SolarNet portal account. Turn off notifications for that account first.',
            ], 409);
        }

        CustomerWebPushSubscription::updateOrCreate(
            ['endpoint' => $endpoint],
            [
                'customer_id' => $customer->id,
                'public_key' => trim((string) data_get($request->input('keys'), 'p256dh')),
                'auth_token' => trim((string) data_get($request->input('keys'), 'auth')),
                'content_encoding' => $request->input('contentEncoding', 'aes128gcm'),
                'device_id' => $request->input('device_id'),
                'platform' => $request->input('platform'),
                'browser' => $request->input('browser'),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'last_used_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
                'revoked_at' => null,
            ],
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Billing and service alerts are enabled for this device.',
        ]);
    }

    /** Revoke only the authenticated customer’s subscription for this device. */
    public function unsubscribePushNotifications(Request $request): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        $validator = Validator::make($request->all(), ['endpoint' => 'required|string|max:2000']);
        if ($validator->fails()) {
            return response()->json(['status' => 'error', 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        CustomerWebPushSubscription::query()
            ->where('customer_id', $customer->id)
            ->where('endpoint', trim($request->string('endpoint')->toString()))
            ->update(['revoked_at' => now(), 'last_used_at' => now()]);

        return response()->json(['status' => 'success', 'message' => 'Billing and service alerts are disabled for this device.']);
    }

    /**
     * A click is recorded only for a notification belonging to this authenticated
     * portal account. Notification URLs never authorize access by themselves.
     */
    public function markPushNotificationClicked(Request $request, string $notificationId): JsonResponse
    {
        $customer = $this->getAuthenticatedCustomer($request);
        if (!$customer) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized'], 401);
        }

        if (!preg_match('/^[0-9a-f-]{36}$/i', $notificationId)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid notification reference.'], 422);
        }

        $this->webPushNotificationService->markClicked($customer, $notificationId);

        return response()->json(['status' => 'success']);
    }

    protected function profileChangePayload(CustomerProfileChangeRequest $change): array
    {
        return [
            'id' => $change->id,
            'requested_full_name' => $change->requested_full_name,
            'requested_service_plan' => $change->requestedServicePlan ? [
                'id' => $change->requestedServicePlan->id,
                'name' => $change->requestedServicePlan->name,
                'price' => $change->requestedServicePlan->price,
            ] : null,
            'status' => $change->status,
            'review_notes' => $change->review_notes,
            'created_at' => $change->created_at?->toIso8601String(),
            'reviewed_at' => $change->reviewed_at?->toIso8601String(),
        ];
    }


    /**
     * Get authenticated customer from token with signature verification
     */
    protected function getAuthenticatedCustomer(Request $request): ?Customer
    {
        return $this->customerPortalTokens->authenticate($request);
    }

    /**
     * Public reminder payload for captive-portal or local reminder screens.
     */
    public function paymentReminder(string $customerId): JsonResponse
    {
        $customer = Customer::with('servicePlan')->find($customerId);

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer not found',
            ], 404);
        }

        if (!in_array($customer->status, ['suspended', 'expired'], true)) {
            return response()->json([
                'status' => 'success',
                'message' => 'Customer is currently active',
                'data' => [
                    'customer_id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'full_name' => $customer->full_name,
                    'status' => $customer->status,
                    'active' => true,
                ],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => $this->billingSuspensionService->buildPaymentReminderData($customer),
        ]);
    }

    /**
     * Resolve a customer from IP/MAC data for future hotspot/captive portal use.
     */
    public function resolvePaymentReminder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ip_address' => 'nullable|ip',
            'mac_address' => 'nullable|string|max:32',
            'router_id' => 'nullable|uuid|exists:routers,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $customer = $this->billingSuspensionService->findCustomerByDhcpLease(
            $request->input('ip_address'),
            $request->input('mac_address'),
            $request->input('router_id')
        );

        if (!$customer) {
            return response()->json([
                'status' => 'error',
                'message' => 'Customer could not be identified from the lease data',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'customer_id' => $customer->id,
                'resolver_data' => $this->billingSuspensionService->buildPaymentReminderData($customer),
                'redirect_url' => CustomerPortalUrl::to('/payment-required/' . $customer->id),
            ],
        ]);
    }
}
