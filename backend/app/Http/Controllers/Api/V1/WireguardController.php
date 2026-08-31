<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\WireguardPeer;
use App\Services\MikrotikService;
use App\Services\WireguardPeerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WireguardController extends Controller
{
    public function __construct(private readonly MikrotikService $mikrotik, private readonly WireguardPeerService $wireguard) {}

    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => [
            'peers' => WireguardPeer::with('router:id,name,host,connection_status')->orderBy('name')->get(),
            'routers' => Router::where('is_active', true)->orderBy('name')->get(['id', 'name', 'host', 'connection_status']),
            'safety' => 'SolarNet stores public keys only. Private keys, host firewall access, and Docker control are never exposed to the web application.',
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $peer = WireguardPeer::create($this->validated($request));
        return response()->json(['success' => true, 'message' => 'WireGuard peer profile saved. No router or VPS configuration was changed.', 'data' => $peer->load('router:id,name,host,connection_status')], 201);
    }

    public function update(Request $request, WireguardPeer $peer): JsonResponse
    {
        $peer->update($this->validated($request, $peer));
        return response()->json(['success' => true, 'message' => 'WireGuard peer profile updated. No network configuration was changed.', 'data' => $peer->fresh()->load('router:id,name,host,connection_status')]);
    }

    public function destroy(WireguardPeer $peer): JsonResponse
    {
        $peer->delete();
        return response()->json(['success' => true, 'message' => 'WireGuard inventory record deleted. RouterOS and VPS configuration were left untouched.']);
    }

    public function scripts(WireguardPeer $peer): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->wireguard->scripts($peer)]);
    }

    public function inspect(WireguardPeer $peer): JsonResponse
    {
        $result = $this->mikrotik->wireguardPeerStatus($peer->router, $peer->interface_name, $peer->server_public_key);
        if (! $result['success']) {
            $peer->update(['last_tested_at' => now(), 'last_test_status' => 'failed', 'last_error' => $result['message']]);
            return response()->json(['success' => false, 'message' => $result['message'], 'code' => $result['code'] ?? null], 422);
        }
        $data = $result['data'];
        $peer->update(['rx_bytes' => $data['rx_bytes'], 'tx_bytes' => $data['tx_bytes'], 'last_tested_at' => now(), 'last_test_status' => $data['latest_handshake'] ? 'handshake_seen' : 'waiting', 'last_error' => null]);
        return response()->json(['success' => true, 'message' => $data['latest_handshake'] ? 'RouterOS reports a WireGuard handshake.' : 'Peer exists, but RouterOS has not reported a handshake yet.', 'data' => $data]);
    }

    public function test(WireguardPeer $peer): JsonResponse
    {
        $target = explode('/', $peer->server_tunnel_address, 2)[0];
        $result = $this->mikrotik->ping($peer->router, $target, 4);
        $received = collect($result['rows'] ?? [])->filter(fn (array $row) => isset($row['time']))->count();
        $ok = ($result['success'] ?? false) && $received > 0;
        $peer->update(['last_tested_at' => now(), 'last_test_status' => $ok ? 'connected' : 'failed', 'last_error' => $ok ? null : ($result['message'] ?? 'No tunnel ping reply received.')]);
        return response()->json(['success' => $ok, 'message' => $ok ? "Tunnel reached {$target} ({$received}/4 replies)." : "Tunnel did not reach {$target}.", 'data' => ['target' => $target, 'received' => $received, 'rows' => $result['rows'] ?? []]], $ok ? 200 : 422);
    }

    private function validated(Request $request, ?WireguardPeer $peer = null): array
    {
        $key = ['required', 'string', 'size:44', 'regex:/^[A-Za-z0-9+\\/]{43}=$/'];
        $cidr = function (string $attribute, mixed $value, \Closure $fail): void {
            [$ip, $prefix] = array_pad(explode('/', (string) $value, 2), 2, null);
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || ! ctype_digit((string) $prefix) || (int) $prefix > 32) {
                $fail("The {$attribute} must be a valid IPv4 CIDR address.");
            }
        };
        return $request->validate([
            'router_id' => ['required', 'uuid', 'exists:routers,id'],
            'name' => ['required', 'string', 'max:100'],
            'interface_name' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'router_public_key' => $key,
            'server_public_key' => $key,
            'server_endpoint' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'server_port' => ['required', 'integer', 'between:1,65535'],
            'server_tunnel_address' => ['required', 'string', $cidr],
            'peer_tunnel_address' => ['required', 'string', $cidr, Rule::unique('wireguard_peers')->ignore($peer?->id)],
            'router_listen_port' => ['required', 'integer', 'between:1,65535'],
            'persistent_keepalive' => ['required', 'integer', 'between:0,300'],
            'enabled' => ['boolean'],
        ]);
    }
}
