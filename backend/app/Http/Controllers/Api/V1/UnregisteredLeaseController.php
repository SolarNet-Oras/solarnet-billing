<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\Router;
use App\Models\ServicePlan;
use App\Services\CustomerAccountService;
use App\Services\DhcpSyncService;
use App\Services\MikrotikService;
use App\Services\InvoiceService;
use App\Services\QueueService;
use App\Services\TechnicianMacMatchService;
use App\Support\CustomerPortalUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Presents unregistered DHCP leases so admins can convert them
 * into billable customers.
 *
 *  - Static leases WITH a comment  -> "quick register" using the comment
 *    as the customer's name and the rate-limit as the subscription hint.
 *  - Dynamic OR uncommented leases -> "manual add" flow (frontend opens
 *    the CreateCustomer form with the MAC / IP prefilled).
 */
class UnregisteredLeaseController extends Controller
{
    protected CustomerAccountService $accountService;
    protected QueueService $queueService;

    public function __construct(CustomerAccountService $accountService, QueueService $queueService, protected InvoiceService $invoiceService, protected TechnicianMacMatchService $technicianMacMatchService)
    {
        $this->accountService = $accountService;
        $this->queueService   = $queueService;
    }

    /**
     * Sync DHCP leases from ALL active routers and return counts.
     * Leases are stored in dhcp_leases; no customer is auto-created.
     */
    public function syncAll(Request $request): JsonResponse
    {
        $service = app(DhcpSyncService::class);
        // This endpoint backs an interactive list refresh. It must mirror
        // RouterOS state only; static-lease and queue writes are handled in a
        // bounded scheduled batch after an exact match has been verified.
        $result  = $service->syncAllRouters(false, false); // never auto-create or write RouterOS

        $failures = collect($result['routers'])
            ->filter(fn (array $routerResult) => !empty($routerResult['errors']))
            ->map(fn (array $routerResult) => $routerResult['router'] ?? 'Unknown router')
            ->values()
            ->all();

        return response()->json([
            // An all-router refresh can partially succeed. Keep its HTTP
            // response usable so the screen can show the imported leases,
            // while making the affected router names explicit to the user.
            'success' => empty($failures),
            'message' => empty($failures)
                ? 'DHCP leases synchronized from all eligible routers.'
                : 'DHCP sync needs attention for: ' . implode(', ', $failures) . '.',
            'data'    => $result,
        ]);
    }

    /**
     * Static leases with a MikroTik comment -> ready for 1-click registration.
     */
    public function staticCommented(Request $request): JsonResponse
    {
        $leases = DhcpLease::with('router:id,name')
            ->unmatched()
            ->active()
            ->presentOnRouter()
            ->where('is_dynamic', false)
            ->whereNotNull('comment')
            ->where('comment', '!=', '')
            ->orderBy('last_seen_at', 'desc')
            ->get();

        // Attach a suggested service plan based on rate_limit
        $plans = ServicePlan::where('is_active', true)->get();
        $leases->each(function (DhcpLease $lease) use ($plans) {
            $lease->suggested_plan = $this->matchPlanByRateLimit($lease->rate_limit, $plans);
        });

        return response()->json([
            'success' => true,
            'data'    => $leases,
        ]);
    }

    /**
     * Dynamic OR uncommented leases -> require manual registration.
     */
    public function dynamic(Request $request): JsonResponse
    {
        $leases = DhcpLease::with('router:id,name')
            ->unmatched()
            ->active()
            ->presentOnRouter()
            ->where(function ($q) {
                $q->where('is_dynamic', true)
                  ->orWhereNull('comment')
                  ->orWhere('comment', '');
            })
            // Filter out entries that are BOTH static AND commented (covered by other tab)
            ->orderBy('last_seen_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $leases,
        ]);
    }

    /**
     * Customers that may be linked to an unregistered DHCP lease. This is a
     * small purpose-built list so a DHCP administrator does not need the
     * general customer-list permission just to bind replacement hardware.
     */
    public function customerLinkCandidates(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        $customers = Customer::query()
            ->with('servicePlan:id,name,price,download_speed,upload_speed')
            ->where('status', '!=', 'pending')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($nested) use ($search) {
                    $nested->where('full_name', 'ilike', "%{$search}%")
                        ->orWhere('account_number', 'ilike', "%{$search}%")
                        ->orWhere('address', 'ilike', "%{$search}%");
                });
            })
            ->orderBy('full_name')
            ->limit(100)
            ->get(['id', 'account_number', 'full_name', 'address', 'status', 'service_plan_id', 'monthly_fee', 'mac_address']);

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }

    /**
     * Register a field-installed client from the technician dashboard.
     *
     * Exact MAC matches bind immediately. A unique unregistered lease that
     * differs only in the final MAC character is automatically corrected to
     * the MikroTik MAC. Other 90%+ fuzzy matches still require explicit
     * confirmation. If no current lease exists, the registration is saved as
     * pending and DHCP sync will bind it later on an exact MAC match.
     */
    public function technicianRegister(Request $request): JsonResponse
    {
        // Preserve the existing MAC-registration API for an in-flight or
        // cached technician page. New pages send the explicit lookup type.
        $request->merge([
            'binding_lookup' => $request->input('binding_lookup', 'mac'),
        ]);

        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'address' => 'required|string|max:1000',
            'contact_number' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'installation_date' => 'required|date',
            'service_plan_id' => 'required|exists:service_plans,id',
            'binding_lookup' => 'required|in:mac,ip',
            'mac_address' => 'nullable|required_if:binding_lookup,mac|string|max:32',
            'ip_address' => 'nullable|required_if:binding_lookup,ip|ip',
            'gps_coordinates' => 'required|array',
            'gps_coordinates.latitude' => 'required|numeric|between:-90,90',
            'gps_coordinates.longitude' => 'required|numeric|between:-180,180',
            'location_accuracy_meters' => 'required|numeric|min:0|max:100000',
            'confirm_fuzzy_match' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $bindingLookup = $data['binding_lookup'];
        $resolvedLeaseByIp = null;

        if ($bindingLookup === 'ip') {
            // An IP address is safe only as a selector for a single *current*
            // unregistered bound lease. IP ranges may overlap between routers,
            // so an ambiguous IP never selects a customer or changes RouterOS.
            $ipMatches = DhcpLease::with('router:id,name')
                ->unmatched()
                ->active()
                ->presentOnRouter()
                ->where('ip_address', $data['ip_address'])
                ->whereNotNull('mac_address')
                ->get();

            if ($ipMatches->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No current unregistered bound DHCP lease uses this IP address. Refresh the lease mirror, or register with the device MAC address instead.',
                ], 422);
            }

            if ($ipMatches->count() !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'This IP address exists on more than one current unregistered lease. Use the full MAC address to avoid binding the wrong customer.',
                    'matches' => $ipMatches->map(fn (DhcpLease $lease) => [
                        'lease_id' => $lease->id,
                        'mac_address' => $lease->mac_address,
                        'ip_address' => $lease->ip_address,
                        'hostname' => $lease->hostname,
                        'comment' => $lease->comment,
                        'router' => $lease->router?->name,
                    ])->values(),
                ], 422);
            }

            $resolvedLeaseByIp = $ipMatches->first();
            $inputMac = $this->normalizeMacForMatch($resolvedLeaseByIp->mac_address);
            if (!$inputMac) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected DHCP lease has an invalid MAC address. Use the full verified MAC address instead.',
                ], 422);
            }
        } else {
            $inputMac = $this->normalizeMacForMatch($data['mac_address'] ?? null);
            if (!$inputMac) {
                return response()->json(['success' => false, 'message' => 'Enter a valid 12-character ONU/router MAC address.'], 422);
            }
        }

        $plan = ServicePlan::query()
            ->whereKey($data['service_plan_id'])
            ->where('is_active', true)
            ->whereRaw("LOWER(name) NOT LIKE '%company owned%'")
            ->first();
        if (!$plan) {
            return response()->json(['success' => false, 'message' => 'Select an active customer service plan. Company Owned is not available for field registration.'], 422);
        }

        $macHex = str_replace(':', '', $inputMac);
        $existingCustomer = Customer::query()
            ->whereRaw("upper(replace(replace(mac_address, ':', ''), '-', '')) = ?", [$macHex])
            ->first();
        if ($existingCustomer) {
            if ($existingCustomer->status === 'pending' && $existingCustomer->mac_binding_status === 'waiting_for_match') {
                return response()->json([
                    'success' => false,
                    'message' => 'A waiting registration already exists for this MAC. It will bind automatically when the exact DHCP lease appears.',
                    'binding_status' => 'waiting_for_match',
                    'data' => $existingCustomer->load('servicePlan'),
                ], 409);
            }

            return response()->json([
                'success' => false,
                'message' => 'This MAC address is already assigned to a customer. Verify the ONU MAC before registering another account.',
            ], 422);
        }

        $candidates = $resolvedLeaseByIp
            ? collect([$resolvedLeaseByIp])->map(function (DhcpLease $lease) {
                // The IP lookup already proved this one live lease is unique.
                // Mark it as an exact derived-MAC match; no fuzzy comparison
                // and no automatic character correction are involved.
                $lease->mac_match_score = 100.0;
                $lease->mac_match_type = 'exact';
                $lease->mac_corrected_from_input = false;
                return $lease;
            })
            : DhcpLease::with('router:id,name')
                ->unmatched()
                ->active()
                ->presentOnRouter()
                ->whereNotNull('mac_address')
                ->get()
                ->map(function (DhcpLease $lease) use ($inputMac) {
                    $leaseMac = $this->normalizeMacForMatch($lease->mac_address);
                    if (!$leaseMac) return null;
                    $comparison = $this->technicianMacMatchService->compare($inputMac, $leaseMac);
                    $lease->mac_match_score = $comparison['score'];
                    $lease->mac_match_type = $comparison['type'];
                    $lease->mac_corrected_from_input = $comparison['type'] === 'last_character_correction';
                    return $lease;
                })
                ->filter(fn (?DhcpLease $lease) => $lease && $lease->mac_match_score >= 90)
                ->sortByDesc('mac_match_score')
                ->values();

        if ($candidates->isEmpty()) {
            try {
                $customer = DB::transaction(function () use ($data, $inputMac, $plan): Customer {
                    return Customer::create([
                        'account_number' => $this->generateAccountNumber(),
                        'full_name' => $data['full_name'],
                        'address' => $data['address'],
                        'contact_number' => $data['contact_number'],
                        'email' => $data['email'] ?? null,
                        'installation_date' => \Carbon\Carbon::parse($data['installation_date'])->startOfDay(),
                        'service_plan_id' => $plan->id,
                        'monthly_fee' => $plan->price,
                        'mac_address' => $inputMac,
                        'mac_binding_status' => 'waiting_for_match',
                        'gps_coordinates' => $data['gps_coordinates'] ?? null,
                        'location_status' => !empty($data['gps_coordinates']) ? 'confirmed' : 'not_captured',
                        'location_source' => !empty($data['gps_coordinates']) ? 'technician_registration' : null,
                        'location_accuracy_meters' => $data['location_accuracy_meters'] ?? null,
                        'location_captured_at' => !empty($data['gps_coordinates']) ? now() : null,
                        'location_confirmed_at' => !empty($data['gps_coordinates']) ? now() : null,
                        'status' => 'pending',
                        'notes' => 'Technician registration saved before the ONU appeared in DHCP. Waiting for an exact current bound lease MAC match; no RouterOS changes were made.',
                    ]);
                });
            } catch (\Throwable $e) {
                Log::error('Failed to save waiting technician registration', [
                    'mac' => $inputMac,
                    'error' => $e->getMessage(),
                ]);
                return response()->json(['success' => false, 'message' => 'Could not save the waiting registration: ' . $e->getMessage()], 500);
            }

            return response()->json([
                'success' => true,
                'message' => 'Client registration saved. Waiting for an exact matching MAC in a current bound DHCP lease.',
                'binding_status' => 'waiting_for_match',
                'binding_lookup' => 'mac',
                'mac_match' => [
                    'type' => 'waiting_for_match',
                    'score' => null,
                    'entered_mac' => $inputMac,
                ],
                'data' => $customer->load('servicePlan'),
            ], 201);
        }

        $bestScore = (float) $candidates->first()->mac_match_score;
        $best = $candidates->filter(fn (DhcpLease $lease) => (float) $lease->mac_match_score === $bestScore)->values();
        if ($best->count() !== 1) {
            return response()->json([
                'success' => false,
                'message' => 'More than one unregistered lease matches this MAC. Use the exact MAC to avoid binding the wrong client.',
                'matches' => $best->map(fn (DhcpLease $lease) => $this->technicianMatchPayload($lease))->values(),
            ], 422);
        }

        $lease = $best->first();
        if ($lease->mac_match_type === 'fuzzy_90_plus' && !$request->boolean('confirm_fuzzy_match')) {
            return response()->json([
                'success' => false,
                'requires_confirmation' => true,
                'message' => 'A 90%+ MAC match was found. Confirm the displayed lease before binding it.',
                'match' => $this->technicianMatchPayload($lease),
            ], 409);
        }

        if (!$lease->is_current || $lease->status !== 'bound' || $lease->is_matched || $lease->customer_id || !$lease->router) {
            return response()->json(['success' => false, 'message' => 'This DHCP lease is no longer a current unregistered bound lease. Refresh the technician dashboard and try again.'], 422);
        }

        $request->merge([
            'full_name' => $data['full_name'],
            'address' => $data['address'],
            'contact_number' => $data['contact_number'],
            'email' => $data['email'] ?? null,
            'installation_date' => $data['installation_date'],
            'service_plan_id' => $plan->id,
            'monthly_fee' => $plan->price,
        ]);

        $response = $this->quickRegister($request, $lease->id);
        $response->setData(array_merge((array) $response->getData(true), [
            'binding_lookup' => $bindingLookup,
            'mac_match' => [
                'type' => $lease->mac_match_type,
                'score' => (float) $lease->mac_match_score,
                'lease_mac' => $lease->mac_address,
                'entered_mac' => $inputMac,
            ],
            'mac_correction' => [
                'applied' => (bool) ($lease->mac_corrected_from_input ?? false),
                'entered_mac' => $inputMac,
                'mikrotik_mac' => $lease->mac_address,
                'reason' => 'The only discrepancy was the final MAC character; the current MikroTik lease MAC was used.',
            ],
        ]));
        return $response;
    }

    /**
     * One-click convert a static+commented lease into a Customer.
     * Uses lease comment as full_name and rate_limit to match a ServicePlan.
     *
     * MikroTik update rules (applied AFTER the customer is safely persisted):
     *   - Any current bound lease -> convert to static if needed, set a
     *                                 SolarNet account ownership comment, and
     *                                 apply the selected plan's rate-limit.
     *
     * MikroTik failures NEVER cause the registration to fail — the customer is
     * already committed by then. We surface the sync result in the response.
     */
    public function quickRegister(Request $request, string $id): JsonResponse
    {
        $lease = DhcpLease::with('router')->find($id);
        if (!$lease) {
            return response()->json(['success' => false, 'message' => 'Lease not found'], 404);
        }

        if ($lease->is_matched) {
            return response()->json(['success' => false, 'message' => 'Lease is already registered'], 422);
        }

        $validator = Validator::make($request->all(), [
            'existing_customer_id' => 'nullable|uuid|exists:customers,id',
            'full_name'       => 'nullable|string|max:255',
            'service_plan_id' => 'nullable|exists:service_plans,id',
            'contact_number'  => 'nullable|string|max:20',
            'address'         => 'nullable|string',
            'email'           => 'nullable|email|max:255',
            'monthly_fee'     => 'nullable|numeric|min:0',
            'installation_date' => 'nullable|date',
            'gps_coordinates' => 'nullable|array',
            'gps_coordinates.latitude' => 'required_with:gps_coordinates|numeric|between:-90,90',
            'gps_coordinates.longitude' => 'required_with:gps_coordinates|numeric|between:-180,180',
            'location_accuracy_meters' => 'nullable|numeric|min:0|max:100000',
            'migration_previous_balance' => 'nullable|numeric|min:0',
            'migration_current_balance' => 'nullable|numeric|min:0',
            'migration_due_date' => 'nullable|date|after_or_equal:installation_date',
            'historical_migration' => 'nullable|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }
        if ($request->boolean('historical_migration') && ! $request->filled('installation_date')) {
            return response()->json([
                'success' => false,
                'message' => 'Historical Installation Date is required for an Excel migration. The system will not use today as a fallback.',
            ], 422);
        }

        if (! $lease->is_current || $lease->status !== 'bound' || ! $lease->router || ! $lease->mac_address) {
            return response()->json([
                'success' => false,
                'message' => 'This DHCP lease is no longer a current bound lease. Refresh MikroTik leases and try again; no customer was changed.',
            ], 422);
        }

        $existingCustomerId = $request->input('existing_customer_id');
        $existingCustomer = null;
        if ($existingCustomerId) {
            $existingCustomer = Customer::query()
                ->with('servicePlan')
                ->whereKey($existingCustomerId)
                ->where('status', '!=', 'pending')
                ->first();

            if (! $existingCustomer) {
                return response()->json([
                    'success' => false,
                    'message' => 'Choose an existing registered customer. Pending applications must be approved through the installation workflow.',
                ], 422);
            }
            if (! $existingCustomer->servicePlan) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected customer has no service plan. Set their plan on the customer record before binding this DHCP lease.',
                ], 422);
            }
        }

        // Retain the source comment in the new customer's notes for audit, but
        // write a clear SolarNet ownership comment to RouterOS after the
        // registration succeeds. This makes manual registration and Sync all
        // use the same account-identifying comment format.
        $originalComment    = trim((string) ($lease->comment ?? ''));
        $wasStaticCommented = !$lease->is_dynamic && $originalComment !== '';

        $fullName = $existingCustomer?->full_name
            ?: ($request->input('full_name') ?: ($originalComment !== '' ? $originalComment : ('Client ' . substr($lease->mac_address, -5))));
        $plans    = ServicePlan::where('is_active', true)->get();
        $planId   = $existingCustomer?->service_plan_id ?: $request->input('service_plan_id');
        if (!$planId) {
            $suggested = $this->matchPlanByRateLimit($lease->rate_limit, $plans);
            $planId    = $suggested['id'] ?? null;
        }
        $plan       = $planId ? ServicePlan::find($planId) : null;
        $monthlyFee = $existingCustomer ? (float) $existingCustomer->monthly_fee : $request->input('monthly_fee', $plan?->price ?? 0);

        // Business rule: rate-limit is ALWAYS derived from the plan (nearest match).
        // Only falls back to the lease's existing rate-limit if no plan is picked.
        $rateLimit = $plan
            ? $plan->download_speed . 'M/' . $plan->upload_speed . 'M'
            : ($lease->rate_limit ?: null);

        try {
            $registration = DB::transaction(function () use ($request, $lease, $fullName, $planId, $monthlyFee, $originalComment, $existingCustomerId) {
                if ($existingCustomerId) {
                    $customer = Customer::query()
                        ->with('servicePlan')
                        ->lockForUpdate()
                        ->whereKey($existingCustomerId)
                        ->where('status', '!=', 'pending')
                        ->firstOrFail();

                    if (! $customer->servicePlan) {
                        throw new \RuntimeException('The selected customer has no service plan. Set their plan before binding this DHCP lease.');
                    }

                    // A selected existing account is a hardware binding only.
                    // Its name, contact, address, installation date, due day,
                    // plan, fee, invoices, and credits are intentionally kept.
                    // Any still-current old lease is detached locally so the
                    // account has one authoritative active DHCP identity.
                    DhcpLease::query()
                        ->where('customer_id', $customer->id)
                        ->where('id', '!=', $lease->id)
                        ->presentOnRouter()
                        ->update([
                            'customer_id' => null,
                            'is_matched' => false,
                            'match_source' => null,
                            'match_note' => 'Local customer lease link replaced after an administrator selected a new DHCP lease.',
                        ]);

                    $customer->update([
                        'router_id' => $lease->router_id,
                        'mac_address' => $lease->mac_address,
                        'ip_address' => $lease->ip_address,
                        'mac_binding_status' => 'matched',
                    ]);
                    $lease->update([
                        'customer_id' => $customer->id,
                        'is_matched' => true,
                        'match_source' => 'existing_customer_link',
                        'match_note' => 'Exact current DHCP lease linked to an existing customer from Unregistered Clients.',
                    ]);

                    return ['customer' => $customer->fresh(), 'opening_invoice' => null, 'linked_existing' => true];
                }

                $installationDate = $request->filled('installation_date') ? \Carbon\Carbon::parse($request->input('installation_date'))->startOfDay() : now()->startOfDay();
                $customer = Customer::create([
                    'account_number'    => $this->generateAccountNumber(),
                    'full_name'         => $fullName,
                    'address'           => $request->input('address', $lease->hostname ?: 'To be updated'),
                    'contact_number'    => $request->input('contact_number', 'N/A'),
                    'email'             => $request->input('email'),
                    'installation_date' => $installationDate->toDateString(),
                    'billing_cycle_day' => $request->filled('migration_due_date')
                        ? \Carbon\Carbon::parse($request->input('migration_due_date'))->day
                        : $installationDate->day,
                    'router_id'         => $lease->router_id,
                    'service_plan_id'   => $planId,
                    'monthly_fee'       => $monthlyFee,
                    'mac_address'       => $lease->mac_address,
                    'mac_binding_status' => 'matched',
                    'ip_address'        => $lease->ip_address,
                    'gps_coordinates'   => $request->input('gps_coordinates'),
                    'location_status'   => $request->filled('gps_coordinates') ? 'confirmed' : 'not_captured',
                    'location_source'   => $request->filled('gps_coordinates') ? 'technician_registration' : null,
                    'location_accuracy_meters' => $request->input('location_accuracy_meters'),
                    'location_captured_at' => $request->filled('gps_coordinates') ? now() : null,
                    'location_confirmed_at' => $request->filled('gps_coordinates') ? now() : null,
                    'status'            => 'active',
                    'notes'             => "Auto-registered from DHCP lease. Original MikroTik comment: {$originalComment}. Lease rate limit was: {$lease->rate_limit}.",
                ]);

                $lease->update([
                    'customer_id' => $customer->id,
                    'is_matched'  => true,
                    'match_source' => 'quick_register',
                    'match_note' => 'Customer created from a current unregistered DHCP lease.',
                ]);

                $openingInvoice = $this->invoiceService->createMigrationOpeningBalanceInvoice(
                    $customer,
                    (float) $request->input('migration_previous_balance', 0),
                    (float) $request->input('migration_current_balance', 0),
                    $installationDate,
                    $request->filled('migration_due_date') ? \Carbon\Carbon::parse($request->input('migration_due_date'))->startOfDay() : null,
                );

                return ['customer' => $customer, 'opening_invoice' => $openingInvoice, 'linked_existing' => false];
            });
            $customer = $registration['customer'];
            $openingInvoice = $registration['opening_invoice'];
            $linkedExisting = $registration['linked_existing'];
        } catch (\Throwable $e) {
            Log::error('Failed to convert lease to customer', [
                'lease_id' => $lease->id,
                'mac'      => $lease->mac_address,
                'error'    => $e->getMessage(),
                'trace'    => collect(explode("\n", $e->getTraceAsString()))->take(3)->all(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to register client: ' . $e->getMessage(),
            ], 500);
        }

        // Optional: provision portal credentials + welcome email if email present
        $portalCreds = null;
        if (! $linkedExisting && !empty($customer->email)) {
            try {
                $plain = $this->accountService->provisionPortalCredentials($customer);
                $sent  = $this->accountService->sendWelcomeEmail($customer, $plain);
                $portalCreds = [
                    'email'              => $customer->email,
                    'password'           => $plain,
                    'portal_url'         => CustomerPortalUrl::to('/customer/login'),
                    'welcome_email_sent' => $sent,
                ];
            } catch (\Throwable $e) {
                Log::warning('Portal credentials/welcome email failed (non-fatal)', [
                    'customer_id' => $customer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Push the customer ownership comment and rate-limit to MikroTik.
        // Wrap in a broad try/catch so ANY MikroTik hiccup is surfaced but does
        // not fail the already-committed registration.
        $mikrotikResult = null;
        if ($lease->router && $lease->mac_address) {
            try {
                $mikrotikComment = $this->customerLeaseComment($customer);

                $mikrotikResult = app(MikrotikService::class)->updateOrMakeStaticLease(
                    $lease->router,
                    $lease->mac_address,
                    $mikrotikComment,
                    $rateLimit,
                    $lease->ip_address,
                    $lease->server ?: 'default'
                );
            } catch (\Throwable $e) {
                Log::warning('MikroTik lease sync raised an exception after register', [
                    'lease_id' => $lease->id, 'error' => $e->getMessage(),
                ]);
                $mikrotikResult = [
                    'success' => false,
                    'message' => 'MikroTik sync exception: ' . $e->getMessage(),
                ];
            }

            // Update local state only once RouterOS confirms it made/updated
            // the static lease. A failed router call must remain visible for
            // the next safe retry rather than being marked static locally.
            if (($mikrotikResult['success'] ?? false) === true) {
                $lease->update([
                    'comment'    => $mikrotikComment,
                    'rate_limit' => $rateLimit,
                    'is_dynamic' => false,
                ]);
            }
        }

        return response()->json([
            'success'            => true,
            'message'            => $linkedExisting ? 'Existing customer linked to DHCP lease' : 'Client registered from DHCP lease',
            'data'               => $customer->load(['servicePlan', 'router']),
            'portal_credentials' => $portalCreds,
            'mikrotik_sync'      => $mikrotikResult,
            'migration_opening_invoice' => $openingInvoice?->fresh(['items']),
            'business_rule'      => [
                'was_static_commented'   => $wasStaticCommented,
                'mikrotik_comment'       => $lease->router && $lease->mac_address ? $this->customerLeaseComment($customer) : null,
                'mikrotik_comment_kept'  => false,
                'linked_existing_customer' => $linkedExisting,
                'rate_limit_pushed'      => $rateLimit,
                'plan_used'              => $plan?->name,
            ],
        ], 201);
    }

    /**
     * Standard RouterOS comment for a registered SolarNet account. The
     * account number remains searchable even where two customers share a
     * similar name, while the original lease comment stays in the customer
     * notes for historical reference.
     */
    protected function customerLeaseComment(Customer $customer): string
    {
        $accountNumber = trim((string) $customer->account_number) ?: 'UNKNOWN';
        $name = trim((string) preg_replace('/\s+/', ' ', str_replace('|', '/', (string) $customer->full_name)));

        return 'SolarNet | ' . $accountNumber . ' | ' . substr($name ?: 'Unnamed customer', 0, 120);
    }

    /**
     * Best-match a plan for a MikroTik rate-limit string.
     * Priority:
     *   1. Exact download AND upload match
     *   2. Exact download match
     *   3. Nearest download speed (business rule: "force to nearest plan")
     * Returns array shape or null.
     */
    protected function matchPlanByRateLimit(?string $rateLimit, $plans): ?array
    {
        if ($plans->isEmpty()) return null;
        if (!$rateLimit) {
            return null;
        }
        // MikroTik format examples: "10M/5M", "10000000/5000000", "10M/5M 20M/10M ..."
        if (!preg_match('/^([\d\.]+)([KkMmGg]?)\s*\/\s*([\d\.]+)([KkMmGg]?)/', $rateLimit, $m)) {
            return null;
        }
        $dl = $this->toMbps((float) $m[1], strtoupper($m[2] ?: 'M'));
        $ul = $this->toMbps((float) $m[3], strtoupper($m[4] ?: 'M'));

        // 1. Exact dl + ul
        $match = $plans->first(function ($p) use ($dl, $ul) {
            return (int) $p->download_speed === (int) round($dl)
                && (int) $p->upload_speed   === (int) round($ul);
        });
        // 2. Exact dl only
        if (!$match) {
            $match = $plans->first(fn ($p) => (int) $p->download_speed === (int) round($dl));
        }
        // 3. Nearest by download (business rule: "force to nearest plan")
        if (!$match) {
            $match = $plans->sortBy(fn ($p) => abs((int) $p->download_speed - (int) round($dl)))->first();
        }
        if (!$match) return null;

        return [
            'id'             => $match->id,
            'name'           => $match->name,
            'price'          => $match->price,
            'download_speed' => $match->download_speed,
            'upload_speed'   => $match->upload_speed,
        ];
    }

    protected function toMbps(float $value, string $unit): float
    {
        switch ($unit) {
            case 'K': return $value / 1000.0;
            case 'G': return $value * 1000.0;
            case 'M':
            default:  return $value;
        }
    }

    protected function normalizeMacForMatch(?string $value): ?string
    {
        $hex = strtoupper((string) preg_replace('/[^0-9A-F]/i', '', (string) $value));
        if (strlen($hex) !== 12 || !ctype_xdigit($hex)) return null;
        return implode(':', str_split($hex, 2));
    }

    protected function technicianMatchPayload(DhcpLease $lease): array
    {
        return [
            'lease_id' => $lease->id,
            'mac_address' => $lease->mac_address,
            'ip_address' => $lease->ip_address,
            'hostname' => $lease->hostname,
            'comment' => $lease->comment,
            'router' => $lease->router?->name,
            'score' => (float) $lease->mac_match_score,
            'match_type' => $lease->mac_match_type,
        ];
    }

    /**
     * Generate a unique 10-digit numeric account number.
     * Business rule: account numbers are 10 digits, no letters.
     */
    protected function generateAccountNumber(): string
    {
        do {
            // 10 random digits (no leading zero to keep it a nice 10-digit number)
            $candidate = (string) random_int(1000000000, 9999999999);
        } while (Customer::where('account_number', $candidate)->exists());
        return $candidate;
    }
}
