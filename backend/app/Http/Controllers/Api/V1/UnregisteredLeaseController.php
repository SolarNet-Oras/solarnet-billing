<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\DhcpLease;
use App\Models\Router;
use App\Models\ServicePlan;
use App\Services\CustomerAccountService;
use App\Services\DhcpSyncService;
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

    public function __construct(CustomerAccountService $accountService, QueueService $queueService)
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
     * One-click convert a static+commented lease into a Customer.
     * Uses lease comment as full_name and rate_limit to match a ServicePlan.
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
        ]);
        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $fullName = $request->input('full_name') ?: ($lease->comment ?: ('Client ' . substr($lease->mac_address, -5)));
        $plans    = ServicePlan::where('is_active', true)->get();
        $planId   = $request->input('service_plan_id');
        if (!$planId) {
            $suggested = $this->matchPlanByRateLimit($lease->rate_limit, $plans);
            $planId    = $suggested['id'] ?? null;
        }
        $plan       = $planId ? ServicePlan::find($planId) : null;
        $monthlyFee = $request->input('monthly_fee', $plan?->price ?? 0);

        try {
            $customer = DB::transaction(function () use ($request, $lease, $fullName, $planId, $monthlyFee) {
                $customer = Customer::create([
                    'account_number'    => $this->generateAccountNumber(),
                    'full_name'         => $fullName,
                    'address'           => $request->input('address', $lease->hostname ?: 'To be updated'),
                    'contact_number'    => $request->input('contact_number', 'N/A'),
                    'email'             => $request->input('email'),
                    'installation_date' => now()->toDateString(),
                    'router_id'         => $lease->router_id,
                    'service_plan_id'   => $planId,
                    'monthly_fee'       => $monthlyFee,
                    'mac_address'       => $lease->mac_address,
                    'ip_address'        => $lease->ip_address,
                    'status'            => 'active',
                    'notes'             => "Auto-registered from DHCP lease. MikroTik comment: {$lease->comment}. Rate limit: {$lease->rate_limit}.",
                ]);

                $lease->update([
                    'customer_id' => $customer->id,
                    'is_matched'  => true,
                ]);

                return $customer;
            });
        } catch (\Throwable $e) {
            Log::error('Failed to convert lease to customer', [
                'lease_id' => $lease->id,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to register client: ' . $e->getMessage(),
            ], 500);
        }

        // Optional: provision portal credentials + welcome email if email present
        $portalCreds = null;
        if (!empty($customer->email)) {
            $plain = $this->accountService->provisionPortalCredentials($customer);
            $sent  = $this->accountService->sendWelcomeEmail($customer, $plain);
            $portalCreds = [
                'email'              => $customer->email,
                'password'           => $plain,
                'portal_url'         => rtrim(config('app.url'), '/') . '/customer/login',
                'welcome_email_sent' => $sent,
            ];
        }

        return response()->json([
            'success'            => true,
            'message'            => 'Client registered from DHCP lease',
            'data'               => $customer->load(['servicePlan', 'router']),
            'portal_credentials' => $portalCreds,
        ], 201);
    }

    /**
     * Simple heuristic: parse "10M/5M" from rate_limit and pick the plan
     * whose download_speed matches. Returns array shape or null.
     */
    protected function matchPlanByRateLimit(?string $rateLimit, $plans): ?array
    {
        if (!$rateLimit) {
            return null;
        }
        // MikroTik format examples: "10M/5M", "10000000/5000000", "10M/5M 20M/10M ..."
        if (!preg_match('/^([\d\.]+)([KkMmGg]?)\s*\/\s*([\d\.]+)([KkMmGg]?)/', $rateLimit, $m)) {
            return null;
        }
        $dl = $this->toMbps((float) $m[1], strtoupper($m[2] ?: 'M'));
        $ul = $this->toMbps((float) $m[3], strtoupper($m[4] ?: 'M'));

        $match = $plans->first(function ($p) use ($dl, $ul) {
            return (int) $p->download_speed === (int) round($dl)
                && (int) $p->upload_speed   === (int) round($ul);
        });
        if (!$match) {
            // Fallback: match download only
            $match = $plans->first(fn ($p) => (int) $p->download_speed === (int) round($dl));
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

    protected function generateAccountNumber(): string
    {
        do {
            $candidate = 'ACC' . strtoupper(substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(6))), 0, 8));
        } while (Customer::where('account_number', $candidate)->exists());
        return $candidate;
    }
}
