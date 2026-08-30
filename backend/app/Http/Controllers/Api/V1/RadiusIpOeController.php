<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncRadiusSubscriber;
use App\Models\Customer;
use App\Models\RadiusAuthorizationLog;
use App\Models\RadiusNasClient;
use App\Models\RadiusSubscriber;
use App\Models\Router;
use App\Services\FreeRadiusSqlSyncService;
use App\Services\RadiusSubscriberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Admin-only RADIUS/IPoE policy inspection. No secret is ever serialized. */
class RadiusIpOeController extends Controller
{
    public function __construct(
        private readonly RadiusSubscriberService $radius,
        private readonly FreeRadiusSqlSyncService $freeRadiusSql,
    ) {}

    public function status(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->radius->configurationStatus()]);
    }

    /**
     * Returns only customers that already have every local prerequisite for a
     * safe policy test. The administrator chooses a customer; the MAC, plan,
     * router, and current IP are read from SolarNet rather than typed again.
     */
    public function testCandidates(): JsonResponse
    {
        $rows = Customer::query()
            ->whereIn('status', ['active', 'suspended', 'expired'])
            ->with([
                'router:id,name,host,is_active',
                'servicePlan:id,name,download_speed,upload_speed',
            ])
            ->select([
                'id', 'account_number', 'full_name', 'status', 'mac_address',
                'ip_address', 'router_id', 'service_plan_id',
            ])
            ->orderBy('full_name')
            ->get()
            ->map(function (Customer $customer): array {
                $mac = RadiusSubscriberService::normalizeMac($customer->mac_address);
                $rateLimit = RadiusSubscriberService::rateLimitFromPlan($customer->servicePlan);

                return [
                    'id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'full_name' => $customer->full_name,
                    'status' => $customer->status,
                    'mac_address' => $mac,
                    'ip_address' => $customer->ip_address,
                    'router' => $customer->router ? [
                        'id' => $customer->router->id,
                        'name' => $customer->router->name,
                    ] : null,
                    'service_plan' => $customer->servicePlan ? [
                        'name' => $customer->servicePlan->name,
                        'download_speed' => (int) $customer->servicePlan->download_speed,
                        'upload_speed' => (int) $customer->servicePlan->upload_speed,
                    ] : null,
                    'rate_limit' => $rateLimit,
                ];
            });

        $macCounts = $rows->pluck('mac_address')->filter()->countBy();
        $candidates = $rows
            ->filter(fn (array $row) => $row['mac_address'] !== null
                && ($macCounts[$row['mac_address']] ?? 0) === 1
                && $row['router'] !== null
                && $row['rate_limit'] !== null)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $candidates,
            'meta' => [
                'eligible' => $candidates->count(),
                'excluded' => $rows->count() - $candidates->count(),
                'message' => 'Only clients with one complete MAC address, an assigned router, and a complete service plan are shown.',
            ],
        ]);
    }

    /** Read-only router choices used only to prefill an isolated NAS form. */
    public function routerCandidates(): JsonResponse
    {
        $routers = Router::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'host', 'connection_status'])
            ->map(fn (Router $router) => [
                'id' => $router->id,
                'name' => $router->name,
                // A management hostname is not necessarily the packet source.
                // Offer only a known literal IPv4 address as a suggestion; the
                // administrator must still confirm the exact NAS source.
                'suggested_source_ip' => filter_var($router->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $router->host : null,
                'connection_status' => $router->connection_status,
            ]);

        return response()->json(['success' => true, 'data' => $routers]);
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $query = RadiusSubscriber::query()->with([
            'customer:id,account_number,full_name,status,service_plan_id',
            'customer.servicePlan:id,name,download_speed,upload_speed',
            'router:id,name',
        ])->latest('last_synced_at');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search): void {
                $q->where('radius_username', 'ilike', "%{$search}%")
                    ->orWhere('mac_address', 'ilike', "%{$search}%")
                    ->orWhereHas('customer', fn ($customer) => $customer->where('account_number', 'ilike', "%{$search}%")->orWhere('full_name', 'ilike', "%{$search}%"));
            });
        }

        $items = $query->paginate($perPage);
        return response()->json(['success' => true, 'data' => $items->items(), 'meta' => [
            'current_page' => $items->currentPage(), 'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(), 'total' => $items->total(),
        ]]);
    }

    public function show(string $customerId): JsonResponse
    {
        $customer = Customer::with(['router:id,name', 'servicePlan:id,name,download_speed,upload_speed'])->findOrFail($customerId);
        $subscriber = RadiusSubscriber::with(['accountingSessions' => fn ($q) => $q->latest('last_interim_at')->limit(10)])
            ->where('customer_id', $customer->id)->first();
        return response()->json(['success' => true, 'data' => [
            'customer' => $customer,
            'subscriber' => $subscriber,
            'policy_preview' => $this->radius->policyForCustomer($customer),
            'logs' => RadiusAuthorizationLog::query()->where('customer_id', $customer->id)->with('actor:id,name')->latest()->limit(25)->get(),
        ]]);
    }

    public function sync(Request $request, string $customerId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        $result = $this->radius->syncForCustomer($customer, 'administrator_sync', $request->user());
        return response()->json(['success' => true, 'message' => 'RADIUS policy staged from current SolarNet billing data. No network device was changed.', 'data' => $result]);
    }

    /**
     * Queue local policy staging for every retained customer. This deliberately
     * does not call a RADIUS server, configure a RouterOS NAS, or touch DHCP.
     */
    public function stageAll(Request $request): JsonResponse
    {
        $queued = 0;
        Customer::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($customers) use ($request, &$queued): void {
                foreach ($customers as $customer) {
                    SyncRadiusSubscriber::dispatch($customer->id, 'administrator_bulk_stage', $request->user()?->id);
                    $queued++;
                }
            });

        return response()->json([
            'success' => true,
            'message' => "Queued local policy staging for {$queued} customer(s). No RADIUS packet, RouterOS, DHCP, HotSpot, firewall, queue, or customer status was changed.",
            'data' => ['queued' => $queued],
        ]);
    }

    public function nasClients(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => RadiusNasClient::query()->with('router:id,name')->orderBy('name')->get(),
        ]);
    }

    public function storeNasClient(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'router_id' => ['nullable', 'uuid', 'exists:routers,id'],
            'name' => ['required', 'string', 'max:128'],
            'nas_address' => ['required', 'ip'],
            'shortname' => ['required', 'string', 'max:64', 'alpha_dash'],
            'shared_secret' => ['required', 'string', 'min:16', 'max:255'],
            'enabled' => ['required', 'boolean'],
            'test_mode' => ['required', 'boolean'],
            'source_verified' => ['required', 'accepted'],
        ]);
        if (!$validated['test_mode']) {
            return response()->json([
                'success' => false,
                'message' => 'Only an isolated test NAS can be approved in this release. Production RouterOS RADIUS rollout is intentionally blocked.',
            ], 422);
        }
        unset($validated['source_verified']);
        $validated['metadata'] = [
            'source_verified_at' => now()->toIso8601String(),
            'source_verified_by' => $request->user()?->id,
        ];
        $nas = RadiusNasClient::create($validated);
        return response()->json([
            'success' => true,
            'message' => 'NAS client saved locally. It is not sent to FreeRADIUS until SQL synchronization is explicitly enabled and you choose Sync NAS.',
            'data' => $nas->load('router:id,name'),
        ], 201);
    }

    public function updateNasClient(Request $request, string $id): JsonResponse
    {
        $nas = RadiusNasClient::findOrFail($id);
        $validated = $request->validate([
            'router_id' => ['nullable', 'uuid', 'exists:routers,id'],
            'name' => ['sometimes', 'string', 'max:128'],
            'nas_address' => ['sometimes', 'ip'],
            'shortname' => ['sometimes', 'string', 'max:64', 'alpha_dash'],
            'shared_secret' => ['nullable', 'string', 'min:16', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'test_mode' => ['sometimes', 'boolean'],
            'source_verified' => ['sometimes', 'accepted'],
        ]);
        $addressChanged = array_key_exists('nas_address', $validated)
            && $validated['nas_address'] !== $nas->nas_address;
        if ($addressChanged && !($validated['source_verified'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => 'Confirm the exact RADIUS packet source before changing the NAS address.',
            ], 422);
        }
        if (!filled($validated['shared_secret'] ?? null)) unset($validated['shared_secret']);
        if (array_key_exists('test_mode', $validated) && !$validated['test_mode']) {
            return response()->json([
                'success' => false,
                'message' => 'Only an isolated test NAS can be approved in this release. Production RouterOS RADIUS rollout is intentionally blocked.',
            ], 422);
        }
        unset($validated['source_verified']);
        if ($addressChanged) {
            $validated['last_synced_at'] = null;
            $validated['last_error'] = null;
            $validated['metadata'] = array_merge($nas->metadata ?? [], [
                'source_verified_at' => now()->toIso8601String(),
                'source_verified_by' => $request->user()?->id,
            ]);
        }
        $nas->fill($validated)->save();
        return response()->json(['success' => true, 'message' => 'NAS client updated locally. FreeRADIUS is unchanged until Sync NAS is selected.', 'data' => $nas->fresh('router:id,name')]);
    }

    public function syncNasClient(string $id): JsonResponse
    {
        $nas = RadiusNasClient::findOrFail($id);
        $result = $this->freeRadiusSql->syncNas($nas);
        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data' => $nas->fresh('router:id,name'),
        ], $result['success'] ? 200 : 422);
    }

    public function test(Request $request, string $customerId): JsonResponse
    {
        $customer = Customer::findOrFail($customerId);
        $result = $this->radius->auditTest($customer, $request->user());
        return response()->json(['success' => true, 'message' => 'Local authorization policy evaluated. No RADIUS packet, HotSpot action, DHCP setting, or MikroTik configuration was changed.', 'data' => $result]);
    }
}
