<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterDnsBrandingAudit;
use App\Models\RouterProvisioningAudit;
use App\Models\RouterQosDeployment;
use App\Models\RouterThreatObservation;
use App\Models\Setting;
use App\Services\MikrotikService;
use App\Services\MikrotikScriptGenerator;
use App\Services\RouterQosService;
use App\Services\RouterDnsBrandingService;
use App\Services\RouterProvisioningService;
use App\Services\ThreatFeedService;
use App\Support\CustomerPortalUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RouterController extends Controller
{
    protected MikrotikService $mikrotikService;
    protected MikrotikScriptGenerator $scriptGenerator;
    protected ThreatFeedService $threatFeedService;
    protected RouterQosService $routerQosService;
    protected RouterDnsBrandingService $routerDnsBrandingService;
    protected RouterProvisioningService $routerProvisioningService;

    public function __construct(MikrotikService $mikrotikService, MikrotikScriptGenerator $scriptGenerator, ThreatFeedService $threatFeedService, RouterQosService $routerQosService, RouterDnsBrandingService $routerDnsBrandingService, RouterProvisioningService $routerProvisioningService)
    {
        $this->mikrotikService = $mikrotikService;
        $this->scriptGenerator = $scriptGenerator;
        $this->threatFeedService = $threatFeedService;
        $this->routerQosService = $routerQosService;
        $this->routerDnsBrandingService = $routerDnsBrandingService;
        $this->routerProvisioningService = $routerProvisioningService;
    }

    /**
     * Display a listing of routers
     */
    public function index(): JsonResponse
    {
        $routers = Router::orderBy('created_at', 'desc')->get();
        
        return response()->json([
            'success' => true,
            'data' => $routers,
        ]);
    }

    /**
     * Store a newly created router
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|min:1|max:65535',
            'username' => 'required|string|max:255',
            'password' => 'required|string',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'dhcp_pool_name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $router = Router::create($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Router added successfully',
            'data' => $router,
        ], 201);
    }

    /**
     * Display the specified router
     */
    public function show(string $id): JsonResponse
    {
        $router = Router::find($id);

        if (!$router) {
            return response()->json([
                'success' => false,
                'message' => 'Router not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $router,
        ]);
    }

    /**
     * Update the specified router
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $router = Router::find($id);

        if (!$router) {
            return response()->json([
                'success' => false,
                'message' => 'Router not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'host' => 'sometimes|required|string|max:255',
            'port' => 'sometimes|required|integer|min:1|max:65535',
            'username' => 'sometimes|required|string|max:255',
            'password' => 'sometimes|required|string',
            'location' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'dhcp_pool_name' => 'nullable|string|max:255',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $router->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Router updated successfully',
            'data' => $router,
        ]);
    }

    /**
     * Remove the specified router
     */
    public function destroy(string $id): JsonResponse
    {
        $router = Router::find($id);

        if (!$router) {
            return response()->json([
                'success' => false,
                'message' => 'Router not found',
            ], 404);
        }

        $router->delete();

        return response()->json([
            'success' => true,
            'message' => 'Router deleted successfully',
        ]);
    }

    /**
     * Test connection to router
     */
    public function testConnection(string $id): JsonResponse
    {
        try {
            $router = Router::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Router not found',
            ], 404);
        }

        $result = $this->mikrotikService->testConnection($router);

        return response()->json($result, $result['success'] ? 200 : 500);
    }

    /** Read live RouterOS counters and firewall threat signals without changing the router. */
    public function monitoring(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $result = $this->mikrotikService->monitoringSnapshot($router);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Run an operator-triggered, read-only comparison with the configured threat feed. */
    public function scanThreatFeed(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $result = $this->threatFeedService->scanRouter($router);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Return audit observations; a pending record is never a firewall change by itself. */
    public function threatObservations(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $observations = RouterThreatObservation::query()
            ->where('router_id', $router->id)
            ->with('reviewer:id,name,email')
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('last_observed_at')
            ->limit(100)
            ->get();

        return response()->json(['success' => true, 'data' => $observations]);
    }

    /** An administrator can dismiss a candidate or explicitly approve its dedicated block. */
    public function reviewThreatObservation(Request $request, string $id, string $observation): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => ['required', Rule::in(['approve_block', 'dismiss'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }

        $router = Router::findOrFail($id);
        $candidate = RouterThreatObservation::query()
            ->where('id', $observation)
            ->where('router_id', $router->id)
            ->firstOrFail();

        if ($candidate->status === 'blocked') {
            return response()->json(['success' => false, 'message' => 'This threat candidate has already been manually blocked.'], 422);
        }

        $data = $validator->validated();
        if ($data['decision'] === 'approve_block') {
            $result = $this->mikrotikService->blockReviewedThreat($router, $candidate->remote_ip, $candidate->feed_name);
            if (!$result['success']) return response()->json($result, 422);

            $candidate->update([
                'status' => 'blocked',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null,
                'blocked_at' => now(),
                'block_expires_at' => now()->addSeconds((int) ($result['timeout_seconds'] ?? 86_400)),
            ]);
        } else {
            $candidate->update([
                'status' => 'dismissed',
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null,
                'block_expires_at' => null,
            ]);
            $result = ['success' => true, 'message' => 'Threat candidate dismissed. No RouterOS change was made.'];
        }

        return response()->json(['success' => true, 'message' => $result['message'], 'data' => $candidate->fresh('reviewer:id,name,email')]);
    }

    /** Read-only RouterOS configuration discovery for the QoS safety workflow. */
    public function qosStatus(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $result = $this->routerQosService->status($router);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function qosConfig(string $id): JsonResponse
    {
        return response()->json($this->routerQosService->configurations(Router::findOrFail($id)));
    }

    /** Existing customer queue/plan data for visibility only. */
    public function qosClients(string $id): JsonResponse
    {
        return response()->json($this->routerQosService->clients(Router::findOrFail($id)));
    }

    public function qosPreview(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'download_capacity_mbps' => ['required', 'numeric', 'min:0.1', 'max:100000'],
            'upload_capacity_mbps' => ['required', 'numeric', 'min:0.1', 'max:100000'],
            'ceiling_percent' => ['nullable', 'numeric', 'min:50', 'max:99'],
            'download_parent' => ['required', 'string', 'max:128'],
            'upload_parent' => ['required', 'string', 'max:128'],
            'mode' => ['nullable', Rule::in(['production', 'test'])],
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);

        $router = Router::findOrFail($id);
        $result = $this->routerQosService->preview($router, $request->user(), $validator->validated());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Preview a single existing SolarNet customer queue for Safe QoS; no RouterOS change. */
    public function qosSafePreview(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'customer_id' => ['required', 'uuid'],
            'test_duration_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'test_target' => ['required', 'string', 'max:253'],
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'A customer, a one-to-sixty minute test duration, and a router ping target are required.', 'errors' => $validator->errors()], 422);

        $result = $this->routerQosService->previewSafe(Router::findOrFail($id), $request->user(), $validator->validated());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Explicitly start the one-customer Safe QoS test after a preview. */
    public function qosSafeStartTest(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['deployment_id' => ['required', 'uuid'], 'confirm_start' => ['required', 'accepted']]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Explicit administrator confirmation is required to start a Safe QoS test.', 'errors' => $validator->errors()], 422);

        $result = $this->routerQosService->startSafeTest(Router::findOrFail($id), RouterQosDeployment::findOrFail($validator->validated()['deployment_id']), $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Retain a Safe QoS queue type only after its scheduled controlled test passed. */
    public function qosSafeApply(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['deployment_id' => ['required', 'uuid'], 'confirm_apply' => ['required', 'accepted']]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Explicit administrator confirmation is required to retain Safe QoS.', 'errors' => $validator->errors()], 422);

        $result = $this->routerQosService->applySafe(Router::findOrFail($id), RouterQosDeployment::findOrFail($validator->validated()['deployment_id']), $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function qosApply(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'deployment_id' => ['required', 'uuid'],
            'confirm_apply' => ['required', 'accepted'],
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Explicit administrator confirmation is required.', 'errors' => $validator->errors()], 422);

        $router = Router::findOrFail($id);
        $deployment = RouterQosDeployment::findOrFail($validator->validated()['deployment_id']);
        $result = $this->routerQosService->apply($router, $deployment, $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function qosRollback(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['deployment_id' => ['required', 'uuid'], 'confirm_rollback' => ['required', 'accepted']]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Explicit rollback confirmation is required.', 'errors' => $validator->errors()], 422);

        $router = Router::findOrFail($id);
        $deployment = RouterQosDeployment::findOrFail($validator->validated()['deployment_id']);
        $result = $this->routerQosService->rollback($router, $deployment, $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function qosDisable(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['confirm_disable' => ['required', 'accepted']]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Explicit emergency-disable confirmation is required.', 'errors' => $validator->errors()], 422);

        $result = $this->routerQosService->disable(Router::findOrFail($id), $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function qosMetrics(string $id): JsonResponse
    {
        $result = $this->routerQosService->metrics(Router::findOrFail($id));
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function qosTest(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['target' => ['required', 'string', 'max:253']]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'A valid QoS test target is required.', 'errors' => $validator->errors()], 422);
        $result = $this->mikrotikService->qosPingTest(Router::findOrFail($id), $validator->validated()['target']);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Read every required RouterOS area before considering a new IPoE setup. */
    public function provisioningDiscover(Request $request, string $id): JsonResponse
    {
        $result = $this->routerProvisioningService->discover(Router::findOrFail($id), $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Persist an administrator-selected plan only; this endpoint never changes RouterOS. */
    public function provisioningPreview(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'audit_id' => ['required', 'uuid'],
            'wan_interface' => ['required', 'string', 'max:128'],
            'customer_parent_interface' => ['required', 'string', 'max:128'],
            'customer_vlan_id' => ['required', 'integer', 'min:2', 'max:4094'],
            'customer_gateway_cidr' => ['required', 'string', 'max:32'],
            'customer_dhcp_pool' => ['required', 'string', 'max:64'],
            'dns_servers' => ['required', 'string', 'max:255'],
            'enable_captive_portal' => ['required', 'boolean'],
            'portal_vlan_id' => ['nullable', 'required_if:enable_captive_portal,true', 'integer', 'min:2', 'max:4094'],
            'portal_gateway_cidr' => ['nullable', 'required_if:enable_captive_portal,true', 'string', 'max:32'],
            'portal_dhcp_pool' => ['nullable', 'required_if:enable_captive_portal,true', 'string', 'max:64'],
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'A complete, isolated IPoE plan is required before preview.', 'errors' => $validator->errors()], 422);

        $router = Router::findOrFail($id);
        $audit = RouterProvisioningAudit::query()->where('id', $validator->validated()['audit_id'])->where('router_id', $router->id)->firstOrFail();
        $result = $this->routerProvisioningService->preview($router, $audit, $validator->validated(), $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Apply a preview only after exact acknowledgement and a final clean-router read. */
    public function provisioningApply(Request $request, string $id): JsonResponse
    {
        $confirmation = 'I understand this router will be configured as a SolarNet IPoE router.';
        $validator = Validator::make($request->all(), [
            'audit_id' => ['required', 'uuid'],
            'confirmation_text' => ['required', 'string', Rule::in([$confirmation])],
        ], [
            'confirmation_text.in' => "Type exactly: {$confirmation}",
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Exact administrator acknowledgement is required before provisioning.', 'errors' => $validator->errors()], 422);

        $router = Router::findOrFail($id);
        $audit = RouterProvisioningAudit::query()->where('id', $validator->validated()['audit_id'])->where('router_id', $router->id)->firstOrFail();
        $result = $this->routerProvisioningService->apply($router, $audit, $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Read RouterOS DNS/DHCP state only; this endpoint cannot change RouterOS. */
    public function dnsBrandingDiscover(Request $request, string $id): JsonResponse
    {
        $result = $this->routerDnsBrandingService->discover(Router::findOrFail($id), $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Scan all routers one at a time. It creates read-only audits and never applies DNS. */
    public function dnsBrandingScanAll(Request $request): JsonResponse
    {
        $results = [];
        foreach (Router::query()->orderBy('name')->get() as $router) {
            $result = $this->routerDnsBrandingService->discover($router, $request->user());
            $results[] = [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'success' => $result['success'],
                'message' => $result['message'],
                'audit_id' => $result['data']['audit']->id ?? null,
            ];
        }
        return response()->json([
            'success' => true,
            'message' => 'Read-only DNS scans completed. No router was modified.',
            'data' => $results,
        ]);
    }

    /** Build a DNS preview; the planner refuses unknown DNS/DHCP objects. */
    public function dnsBrandingPreview(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'audit_id' => ['required', 'uuid'],
            'domain' => ['required', 'string', 'max:253'],
            'records' => ['required', 'array', 'max:30'],
            'records.*.hostname' => ['nullable', 'string', 'max:253'],
            'records.*.type' => ['nullable', Rule::in(['A', 'AAAA', 'CNAME'])],
            'records.*.address' => ['nullable', 'string', 'max:255'],
            'records.*.ttl' => ['nullable', 'integer', 'min:60', 'max:604800'],
            'records.*.description' => ['nullable', 'string', 'max:255'],
            'approved_dhcp_network_ids' => ['nullable', 'array', 'max:100'],
            // RouterOS resource IDs look like *1A, not database UUIDs.
            'approved_dhcp_network_ids.*' => ['string', 'max:64'],
            'remove_record_ids' => ['nullable', 'array', 'max:100'],
            'remove_record_ids.*' => ['string', 'max:64'],
        ]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Check the DNS record form before previewing.', 'errors' => $validator->errors()], 422);

        $router = Router::findOrFail($id);
        $audit = RouterDnsBrandingAudit::query()->where('id', $validator->validated()['audit_id'])->where('router_id', $router->id)->firstOrFail();
        $result = $this->routerDnsBrandingService->preview($router, $audit, $validator->validated());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Save a verified RouterOS backup reference only; DNS remains unchanged. */
    public function dnsBrandingBackup(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['audit_id' => ['required', 'uuid']]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'A DNS preview audit is required before backup.', 'errors' => $validator->errors()], 422);
        $router = Router::findOrFail($id);
        $audit = RouterDnsBrandingAudit::query()->where('id', $validator->validated()['audit_id'])->where('router_id', $router->id)->firstOrFail();
        $result = $this->routerDnsBrandingService->backup($router, $audit);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Run a read-only RouterOS DNS test against previewed/current owned names. */
    public function dnsBrandingTest(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['audit_id' => ['required', 'uuid']]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'A DNS audit is required before testing.', 'errors' => $validator->errors()], 422);
        $router = Router::findOrFail($id);
        $audit = RouterDnsBrandingAudit::query()->where('id', $validator->validated()['audit_id'])->where('router_id', $router->id)->firstOrFail();
        $result = $this->routerDnsBrandingService->test($router, $audit);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Apply only after the exact administrator acknowledgement. */
    public function dnsBrandingApply(Request $request, string $id): JsonResponse
    {
        $confirmation = 'I approve SolarNet internal DNS branding on this router.';
        $validator = Validator::make($request->all(), [
            'audit_id' => ['required', 'uuid'],
            'confirmation_text' => ['required', 'string', Rule::in([$confirmation])],
        ], ['confirmation_text.in' => "Type exactly: {$confirmation}"]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Exact administrator acknowledgement is required before DNS changes.', 'errors' => $validator->errors()], 422);
        $router = Router::findOrFail($id);
        $audit = RouterDnsBrandingAudit::query()->where('id', $validator->validated()['audit_id'])->where('router_id', $router->id)->firstOrFail();
        $result = $this->routerDnsBrandingService->apply($router, $audit, $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Remove only SolarNet-DNS:v1 records from one audit and restore its DHCP values. */
    public function dnsBrandingRollback(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), ['audit_id' => ['required', 'uuid'], 'confirm_rollback' => ['required', 'accepted']]);
        if ($validator->fails()) return response()->json(['success' => false, 'message' => 'Explicit rollback confirmation is required.', 'errors' => $validator->errors()], 422);
        $router = Router::findOrFail($id);
        $audit = RouterDnsBrandingAudit::query()->where('id', $validator->validated()['audit_id'])->where('router_id', $router->id)->firstOrFail();
        $result = $this->routerDnsBrandingService->rollback($router, $audit, $request->user());
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * Trigger manual sync for router
     */
    public function sync(string $id): JsonResponse
    {
        try {
            $router = Router::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Router not found',
            ], 404);
        }

        // Keep the existing router inventory/queue snapshot, then use the
        // verified DHCP sync path as well. The latter is what safely binds
        // registered customers and converts only their current dynamic leases
        // to static leases with the selected service-plan rate limit.
        $result = $this->mikrotikService->syncRouter($router);
        if (($result['synced_items']['system'] ?? false) === true) {
            $dhcpResult = app(\App\Services\DhcpSyncService::class)->syncRouterLeases($router, false);
            $result['dhcp_sync'] = $dhcpResult;
            $result['synced_items']['dhcp_leases'] = $dhcpResult['leases_stored'];
            $result['errors'] = array_values(array_unique(array_merge(
                $result['errors'] ?? [],
                $dhcpResult['errors'] ?? [],
            )));
            $result['success'] = empty($result['errors']);
            $result['message'] = sprintf(
                '%s Registered DHCP leases matched: %d; dynamic leases made static: %d; already static or safely skipped: %d.',
                $result['message'],
                $dhcpResult['customers_matched'],
                $dhcpResult['static_leases_converted'],
                $dhcpResult['static_lease_skipped'],
            );
        }

        return response()->json($result);
    }

    /** Install or replace only the Solarnet payment-only firewall rules. */
    public function installBillingAccess(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $paymentUrl = $this->paymentReminderUrl();
        $result = $this->mikrotikService->installBillingAccessRules($router, $paymentUrl);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Verify the Solarnet payment-only firewall rules without changing the router. */
    public function billingAccessStatus(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $result = $this->mikrotikService->billingAccessRulesStatus($router);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Read-only network safety audit before billing access is installed. */
    public function billingAccessAudit(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $result = $this->mikrotikService->billingAccessAudit($router);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Remove only firewall rules whose comments belong to Solarnet billing. */
    public function removeBillingAccess(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $result = $this->mikrotikService->removeBillingAccessRules($router);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Execute a one-time RouterOS script over the saved API connection. */
    public function runConsoleScript(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'script' => 'required|string|min:1|max:10000',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'A script between 1 and 10,000 characters is required.', 'errors' => $validator->errors()], 422);
        }

        $router = Router::findOrFail($id);
        $result = $this->mikrotikService->runOneTimeScript($router, $request->string('script')->toString(), $request->user()?->id);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /** Run a bounded ping diagnostic from the router. */
    public function consolePing(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'address' => 'required|string|max:255',
            'count' => 'nullable|integer|min:1|max:10',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'A valid ping host/address is required.', 'errors' => $validator->errors()], 422);
        }

        $router = Router::findOrFail($id);
        $result = $this->mikrotikService->ping($router, $request->string('address')->toString(), (int) $request->input('count', 4));
        return response()->json($result, $result['success'] ? 200 : 422);
    }


    /**
     * Generate setup script for router
     */
    public function generateSetupScript(Request $request, string $id): JsonResponse
    {
        try {
            $router = Router::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Router not found',
            ], 404);
        }

        $billingSystemIp = $request->input('billing_system_ip') ?: $this->detectPublicIp();
        $script = $this->scriptGenerator->generateSetupScript($router, $billingSystemIp, $this->paymentReminderUrl());

        return response()->json([
            'success' => true,
            'data' => [
                'script' => $script,
                'billing_system_ip' => $billingSystemIp,
                'router' => [
                    'name' => $router->name,
                    'host' => $router->host,
                    'port' => $router->port,
                ],
            ],
        ]);
    }

    /**
     * Preview a setup script WITHOUT persisting a router record.
     * Used by the "Add Router" wizard so the user can paste the script
     * on MikroTik before saving the router in the app.
     */
    public function previewSetupScript(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required|string|max:100',
            'host'     => 'nullable|string|max:255',
            'port'     => 'nullable|integer|min:1|max:65535',
            'username' => 'required|string|max:64',
            'password' => 'required|string|min:6|max:128',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Build an in-memory Router (unsaved) purely for the script generator
        $router = new Router([
            'name'     => $request->input('name'),
            'host'     => $request->input('host') ?: '0.0.0.0',
            'port'     => (int) ($request->input('port') ?: 8728),
            'username' => $request->input('username'),
            'password' => $request->input('password'),
        ]);

        $billingSystemIp = $request->input('billing_system_ip') ?: $this->detectPublicIp();
        $script = $this->scriptGenerator->generateSetupScript($router, $billingSystemIp, $this->paymentReminderUrl());

        return response()->json([
            'success' => true,
            'data' => [
                'script' => $script,
                'billing_system_ip' => $billingSystemIp,
            ],
        ]);
    }

    /**
     * Return network info the frontend needs for MikroTik setup:
     * - Public IP of this billing server (to whitelist on the MikroTik firewall)
     * - Recommended API port defaults
     */
    public function networkInfo(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'billing_system_ip' => $this->detectPublicIp(),
                'default_api_port'  => 8728,
                'default_ssl_port'  => 8729,
            ],
        ]);
    }

    /** Use an explicit operator setting when present, otherwise the new portal. */
    private function paymentReminderUrl(): string
    {
        return CustomerPortalUrl::paymentReminder((string) Setting::get('network.payment_reminder_url', ''));
    }

    /**
     * Best-effort detection of this server's outbound public IP.
     * Results are cached for 1 hour to avoid hammering the external services.
     */
    protected function detectPublicIp(): ?string
    {
        return \Illuminate\Support\Facades\Cache::remember('billing.public_ip', 3600, function () {
            foreach (['https://api.ipify.org', 'https://ifconfig.me/ip', 'https://ipinfo.io/ip'] as $endpoint) {
                try {
                    $ctx = stream_context_create(['http' => ['timeout' => 3]]);
                    $ip  = @file_get_contents($endpoint, false, $ctx);
                    $ip  = trim((string) $ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        return $ip;
                    }
                } catch (\Throwable $e) {
                    // try next endpoint
                }
            }
            return null;
        });
    }

    /**
     * Get queue management script template
     */
    public function getQueueScript(): JsonResponse
    {
        $script = $this->scriptGenerator->generateQueueManagementScript();

        return response()->json([
            'success' => true,
            'data' => [
                'script' => $script,
            ],
        ]);
    }

    /**
     * Sync DHCP leases for a router
     */
    public function syncDhcpLeases(Request $request, string $id): JsonResponse
    {
        try {
            $router = Router::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Router not found',
            ], 404);
        }

        $dhcpSyncService = app(\App\Services\DhcpSyncService::class);
        // Business rule: MikroTik DHCP sync NEVER auto-creates customers.
        // Leases land on the Unregistered page; admin must click "Register".
        $autoCreate = false;

        $result = $dhcpSyncService->syncRouterLeases($router, $autoCreate);

        return response()->json([
            'success' => empty($result['errors']),
            'data' => $result,
        ]);
    }

    /**
     * Get unmatched DHCP leases
     */
    public function getUnmatchedLeases(Request $request, string $id): JsonResponse
    {
        try {
            $router = Router::findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Router not found',
            ], 404);
        }

        $dhcpSyncService = app(\App\Services\DhcpSyncService::class);
        $leases = $dhcpSyncService->getUnmatchedLeases($router);

        return response()->json([
            'success' => true,
            'data' => $leases,
        ]);
    }

}
