<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\Setting;
use App\Services\MikrotikService;
use App\Services\MikrotikScriptGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RouterController extends Controller
{
    protected MikrotikService $mikrotikService;
    protected MikrotikScriptGenerator $scriptGenerator;

    public function __construct(MikrotikService $mikrotikService, MikrotikScriptGenerator $scriptGenerator)
    {
        $this->mikrotikService = $mikrotikService;
        $this->scriptGenerator = $scriptGenerator;
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

        $result = $this->mikrotikService->syncRouter($router);

        return response()->json($result);
    }

    /** Install or replace only the Solarnet payment-only firewall rules. */
    public function installBillingAccess(string $id): JsonResponse
    {
        $router = Router::findOrFail($id);
        $paymentUrl = trim((string) Setting::get('network.payment_reminder_url', rtrim((string) config('app.url'), '/') . '/customer/login'));
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
        $script = $this->scriptGenerator->generateSetupScript($router, $billingSystemIp, Setting::get('network.payment_reminder_url', config('app.url')));

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
        $script = $this->scriptGenerator->generateSetupScript($router, $billingSystemIp, Setting::get('network.payment_reminder_url', config('app.url')));

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
