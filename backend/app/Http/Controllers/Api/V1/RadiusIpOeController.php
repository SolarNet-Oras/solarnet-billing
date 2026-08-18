<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncRadiusSubscriber;
use App\Models\Customer;
use App\Models\RadiusAuthorizationLog;
use App\Models\RadiusNasClient;
use App\Models\RadiusSubscriber;
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
        ]);
        if (!$validated['test_mode']) {
            return response()->json([
                'success' => false,
                'message' => 'Only an isolated test NAS can be approved in this release. Production RouterOS RADIUS rollout is intentionally blocked.',
            ], 422);
        }
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
        ]);
        if (!filled($validated['shared_secret'] ?? null)) unset($validated['shared_secret']);
        if (array_key_exists('test_mode', $validated) && !$validated['test_mode']) {
            return response()->json([
                'success' => false,
                'message' => 'Only an isolated test NAS can be approved in this release. Production RouterOS RADIUS rollout is intentionally blocked.',
            ], 422);
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
