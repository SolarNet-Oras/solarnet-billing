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

    public function __construct(CustomerAccountService $accountService, QueueService $queueService, protected InvoiceService $invoiceService)
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
        $result  = $service->syncAllRouters(false); // never auto-create; user reviews first

        return response()->json([
            'success' => true,
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
     * Register a field-installed client from the technician dashboard.
     *
     * Exact MAC matches bind immediately. A one-character MAC discrepancy
     * (11/12 positions, at least 90% identical) returns a confirmation
     * preview; the technician must explicitly confirm before binding. If no
     * current lease exists, the registration is saved as pending and DHCP
     * sync will bind it later on an exact MAC match.
     */
    public function technicianRegister(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'full_name' => 'required|string|max:255',
            'address' => 'required|string|max:1000',
            'contact_number' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'installation_date' => 'required|date',
            'service_plan_id' => 'required|exists:service_plans,id',
            'mac_address' => 'required|string|max:32',
            'confirm_fuzzy_match' => 'sometimes|boolean',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();
        $inputMac = $this->normalizeMacForMatch($data['mac_address']);
        if (!$inputMac) {
            return response()->json(['success' => false, 'message' => 'Enter a valid 12-character ONU/router MAC address.'], 422);
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

        $candidates = DhcpLease::with('router:id,name')
            ->unmatched()
            ->active()
            ->presentOnRouter()
            ->whereNotNull('mac_address')
            ->get()
            ->map(function (DhcpLease $lease) use ($inputMac) {
                $leaseMac = $this->normalizeMacForMatch($lease->mac_address);
                if (!$leaseMac) return null;

                $samePositions = 0;
                $inputBytes = str_replace(':', '', $inputMac);
                $leaseBytes = str_replace(':', '', $leaseMac);
                for ($index = 0; $index < 12; $index++) {
                    if ($inputBytes[$index] === $leaseBytes[$index]) $samePositions++;
                }
                $lease->mac_match_score = round(($samePositions / 12) * 100, 1);
                $lease->mac_match_type = $samePositions === 12 ? 'exact' : 'fuzzy_90_plus';
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
        if ($lease->mac_match_type !== 'exact' && !$request->boolean('confirm_fuzzy_match')) {
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
            'mac_match' => [
                'type' => $lease->mac_match_type,
                'score' => (float) $lease->mac_match_score,
                'lease_mac' => $lease->mac_address,
                'entered_mac' => $inputMac,
            ],
        ]));
        return $response;
    }

    /**
     * One-click convert a static+commented lease into a Customer.
     * Uses lease comment as full_name and rate_limit to match a ServicePlan.
     *
     * MikroTik update rules (applied AFTER the customer is safely persisted):
     *   - Static + commented lease  -> keep the existing MikroTik comment as-is,
     *                                  but force the rate-limit to match the nearest plan
     *   - Dynamic / uncommented lease -> convert to static, set comment = customer name,
     *                                  and apply the plan's rate-limit
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
            'full_name'       => 'nullable|string|max:255',
            'service_plan_id' => 'nullable|exists:service_plans,id',
            'contact_number'  => 'nullable|string|max:20',
            'address'         => 'nullable|string',
            'email'           => 'nullable|email|max:255',
            'monthly_fee'     => 'nullable|numeric|min:0',
            'installation_date' => 'nullable|date',
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

        // Snapshot lease's ORIGINAL MikroTik state BEFORE we mutate anything.
        // A "static + commented" lease keeps its MikroTik comment. A dynamic or
        // uncommented lease gets the customer's name pushed to MikroTik as comment.
        $originalComment    = trim((string) ($lease->comment ?? ''));
        $wasStaticCommented = !$lease->is_dynamic && $originalComment !== '';

        $fullName = $request->input('full_name') ?: ($originalComment !== '' ? $originalComment : ('Client ' . substr($lease->mac_address, -5)));
        $plans    = ServicePlan::where('is_active', true)->get();
        $planId   = $request->input('service_plan_id');
        if (!$planId) {
            $suggested = $this->matchPlanByRateLimit($lease->rate_limit, $plans);
            $planId    = $suggested['id'] ?? null;
        }
        $plan       = $planId ? ServicePlan::find($planId) : null;
        $monthlyFee = $request->input('monthly_fee', $plan?->price ?? 0);

        // Business rule: rate-limit is ALWAYS derived from the plan (nearest match).
        // Only falls back to the lease's existing rate-limit if no plan is picked.
        $rateLimit = $plan
            ? $plan->download_speed . 'M/' . $plan->upload_speed . 'M'
            : ($lease->rate_limit ?: null);

        try {
            $registration = DB::transaction(function () use ($request, $lease, $fullName, $planId, $monthlyFee, $originalComment) {
                $installationDate = $request->filled('installation_date') ? \Carbon\Carbon::parse($request->input('installation_date'))->startOfDay() : now()->startOfDay();
                $customer = Customer::create([
                    'account_number'    => $this->generateAccountNumber(),
                    'full_name'         => $fullName,
                    'address'           => $request->input('address', $lease->hostname ?: 'To be updated'),
                    'contact_number'    => $request->input('contact_number', 'N/A'),
                    'email'             => $request->input('email'),
                    'installation_date' => $installationDate->toDateString(),
                    'router_id'         => $lease->router_id,
                    'service_plan_id'   => $planId,
                    'monthly_fee'       => $monthlyFee,
                    'mac_address'       => $lease->mac_address,
                    'mac_binding_status' => 'matched',
                    'ip_address'        => $lease->ip_address,
                    'status'            => 'active',
                    'notes'             => "Auto-registered from DHCP lease. Original MikroTik comment: {$originalComment}. Lease rate limit was: {$lease->rate_limit}.",
                ]);

                $lease->update([
                    'customer_id' => $customer->id,
                    'is_matched'  => true,
                ]);

                $openingInvoice = $this->invoiceService->createMigrationOpeningBalanceInvoice(
                    $customer,
                    (float) $request->input('migration_previous_balance', 0),
                    (float) $request->input('migration_current_balance', 0),
                    $installationDate,
                    $request->filled('migration_due_date') ? \Carbon\Carbon::parse($request->input('migration_due_date'))->startOfDay() : null,
                );

                return ['customer' => $customer, 'opening_invoice' => $openingInvoice];
            });
            $customer = $registration['customer'];
            $openingInvoice = $registration['opening_invoice'];
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
        if (!empty($customer->email)) {
            try {
                $plain = $this->accountService->provisionPortalCredentials($customer);
                $sent  = $this->accountService->sendWelcomeEmail($customer, $plain);
                $portalCreds = [
                    'email'              => $customer->email,
                    'password'           => $plain,
                    'portal_url'         => rtrim(config('app.url'), '/') . '/customer/login',
                    'welcome_email_sent' => $sent,
                ];
            } catch (\Throwable $e) {
                Log::warning('Portal credentials/welcome email failed (non-fatal)', [
                    'customer_id' => $customer->id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        // Push comment (only for dynamic/uncommented) and rate-limit to MikroTik.
        // Wrap in a broad try/catch so ANY MikroTik hiccup is surfaced but does
        // not fail the already-committed registration.
        $mikrotikResult = null;
        if ($lease->router && $lease->mac_address) {
            try {
                // Commented static leases keep their MikroTik comment intact.
                // Dynamic/uncommented leases receive the customer's name.
                $mikrotikComment  = $wasStaticCommented ? $originalComment : $customer->full_name;
                $preserveComment  = $wasStaticCommented;

                $mikrotikResult = app(MikrotikService::class)->updateOrMakeStaticLease(
                    $lease->router,
                    $lease->mac_address,
                    $mikrotikComment,
                    $rateLimit,
                    $lease->ip_address,
                    'default',
                    $preserveComment
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

            // Reflect the FINAL state locally: is_dynamic=false, rate_limit=plan-forced,
            // and comment reflects whichever comment we settled on (kept vs pushed).
            $lease->update([
                'comment'    => $wasStaticCommented ? $originalComment : $customer->full_name,
                'rate_limit' => $rateLimit,
                'is_dynamic' => false,
            ]);
        }

        return response()->json([
            'success'            => true,
            'message'            => 'Client registered from DHCP lease',
            'data'               => $customer->load(['servicePlan', 'router']),
            'portal_credentials' => $portalCreds,
            'mikrotik_sync'      => $mikrotikResult,
            'migration_opening_invoice' => $openingInvoice?->fresh(['items']),
            'business_rule'      => [
                'was_static_commented'   => $wasStaticCommented,
                'mikrotik_comment_kept'  => $wasStaticCommented,
                'rate_limit_pushed'      => $rateLimit,
                'plan_used'              => $plan?->name,
            ],
        ], 201);
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
