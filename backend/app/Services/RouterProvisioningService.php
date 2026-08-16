<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterProvisioningAudit;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Guarded provisioning for a new, IPoE-only MikroTik.
 *
 * This service never attempts to migrate an existing network. Discovery must
 * prove the router clean, the generated plan is saved for audit, a RouterOS
 * backup must verify, and only explicitly SolarNet-owned resources can be
 * rolled back after a failed verification.
 */
class RouterProvisioningService
{
    public function __construct(private readonly MikrotikService $mikrotikService)
    {
    }

    public function discover(Router $router, User $user): array
    {
        $result = $this->mikrotikService->cleanProvisioningDiscovery($router);
        if (!$result['success']) return $result;

        $discovery = $result['data'];
        $audit = RouterProvisioningAudit::create([
            'router_id' => $router->id,
            'status' => $discovery['clean'] ? 'discovered_clean' : 'refused_not_clean',
            'discovery' => $discovery,
            'discovered_by' => $user->id,
        ]);

        return [
            'success' => true,
            'message' => $discovery['clean']
                ? 'Read-only discovery completed. This router appears clean enough for a generated IPoE provisioning plan.'
                : 'ROUTER IS NOT CLEAN. No RouterOS configuration was changed.',
            'data' => ['audit' => $audit, 'discovery' => $discovery],
        ];
    }

    /** Build and persist a plan only; this makes no RouterOS change. */
    public function preview(Router $router, RouterProvisioningAudit $audit, array $input, User $user): array
    {
        if ($audit->router_id !== $router->id || $audit->status !== 'discovered_clean') {
            return ['success' => false, 'message' => 'A current clean-router discovery is required before SolarNet can generate a provisioning plan.'];
        }

        $planResult = $this->buildPlan($audit->discovery ?? [], $input);
        if (!$planResult['success']) return $planResult;

        $audit->update([
            'status' => 'previewed',
            'plan' => $planResult['data'],
            'approved_by' => null,
            'approved_at' => null,
            'failure_reason' => null,
        ]);

        return [
            'success' => true,
            'message' => 'SolarNet IPoE provisioning plan generated. No RouterOS configuration was changed.',
            'data' => ['audit' => $audit->fresh(), 'plan' => $planResult['data']],
        ];
    }

    public function apply(Router $router, RouterProvisioningAudit $audit, User $user): array
    {
        if ($audit->router_id !== $router->id || $audit->status !== 'previewed' || !is_array($audit->plan)) {
            return ['success' => false, 'message' => 'Only a current, clean-router provisioning preview can be approved and applied.'];
        }

        // Re-discovery prevents a plan from being applied after another
        // administrator has changed the router since it was previewed.
        $fresh = $this->mikrotikService->cleanProvisioningDiscovery($router);
        if (!$fresh['success']) return $fresh;
        if (!($fresh['data']['clean'] ?? false)) {
            $audit->update(['status' => 'refused_not_clean', 'discovery' => $fresh['data'], 'failure_reason' => 'Router changed after preview and is no longer clean.']);
            return ['success' => false, 'message' => 'ROUTER IS NOT CLEAN on the final inspection. No RouterOS configuration was changed.', 'data' => ['discovery' => $fresh['data']]];
        }

        $plan = $audit->plan;
        $audit->update(['status' => 'applying', 'approved_by' => $user->id, 'approved_at' => now(), 'applied_by' => $user->id, 'applied_at' => now(), 'discovery' => $fresh['data']]);

        $backup = $this->mikrotikService->createQosBackup($router, 'solarnet-provisioning-' . now()->format('YmdHis') . '-' . substr((string) Str::uuid(), 0, 8));
        if (!$backup['success']) {
            $audit->update(['status' => 'failed', 'failure_reason' => $backup['message']]);
            return ['success' => false, 'message' => 'Provisioning was blocked because the RouterOS backup could not be verified. ' . $backup['message']];
        }
        $audit->update(['backup_filename' => $backup['backup_file']]);

        $apply = $this->mikrotikService->runOneTimeScript($router, $this->applyScript($plan), $user->email);
        if (!$apply['success']) return $this->rollbackAfterFailure($router, $audit, $plan, $apply['message']);

        $paymentUrl = (string) Setting::get('network.payment_reminder_url', rtrim((string) config('app.url'), '/') . '/customer/login');
        $billing = $this->mikrotikService->installBillingAccessRules($router, $paymentUrl);
        if (!$billing['success']) return $this->rollbackAfterFailure($router, $audit, $plan, 'Billing access infrastructure could not be installed: ' . $billing['message']);

        $verification = $this->mikrotikService->verifyCleanProvisioning($router, $plan);
        if (!$verification['success']) return $this->rollbackAfterFailure($router, $audit, $plan, $verification['message'] ?? 'Provisioning verification failed.', $verification['data'] ?? null);

        $audit->update([
            'status' => 'verified_pending_ipoe_client_test',
            'verification' => $verification['data'],
            'verified_at' => now(),
            'failure_reason' => null,
        ]);

        return [
            'success' => true,
            'message' => 'SolarNet IPoE base infrastructure was applied and verified. Connect and test one IPoE client before using this router for production customers.',
            'data' => ['audit' => $audit->fresh(), 'verification' => $verification['data']],
        ];
    }

    private function rollbackAfterFailure(Router $router, RouterProvisioningAudit $audit, array $plan, string $reason, ?array $verification = null): array
    {
        $rollback = $this->mikrotikService->runOneTimeScript($router, $this->rollbackScript($plan), 'SolarNet provisioning rollback');
        $this->mikrotikService->removeBillingAccessRules($router);
        $audit->update([
            'status' => $rollback['success'] ? 'rolled_back' : 'failed',
            'failure_reason' => $reason . ' Rollback: ' . ($rollback['message'] ?? 'not attempted'),
            'verification' => $verification,
            'rolled_back_at' => $rollback['success'] ? now() : null,
        ]);

        return [
            'success' => false,
            'message' => $rollback['success']
                ? 'Provisioning verification failed and SolarNet-owned base resources were rolled back. ' . $reason
                : 'Provisioning failed and SolarNet could not confirm rollback. Restore the verified RouterOS backup before making any further changes. ' . $reason,
            'data' => ['audit' => $audit->fresh(), 'rollback' => $rollback],
        ];
    }

    private function buildPlan(array $discovery, array $input): array
    {
        if (!($discovery['clean'] ?? false)) return ['success' => false, 'message' => 'ROUTER IS NOT CLEAN. A configuration plan cannot be generated.'];

        $running = array_values($discovery['running_interfaces'] ?? []);
        $wan = (string) ($input['wan_interface'] ?? '');
        $parent = (string) ($input['customer_parent_interface'] ?? '');
        if (!in_array($wan, $running, true)) return ['success' => false, 'message' => 'Select a confirmed running WAN interface. SolarNet will not guess that ether1 is WAN.'];
        if (!in_array($parent, $running, true) || $parent === $wan) return ['success' => false, 'message' => 'Select a different confirmed running customer parent interface. SolarNet will not add or move bridge ports automatically.'];

        $customerVlan = (int) ($input['customer_vlan_id'] ?? 0);
        if ($customerVlan < 2 || $customerVlan > 4094) return ['success' => false, 'message' => 'Customer VLAN ID must be between 2 and 4094.'];
        $gateway = (string) ($input['customer_gateway_cidr'] ?? '');
        $pool = (string) ($input['customer_dhcp_pool'] ?? '');
        $network = $this->networkForCidr($gateway);
        if (!$network || !$this->validPool($pool, $gateway)) return ['success' => false, 'message' => 'Use a valid IPv4 customer gateway CIDR and DHCP pool inside that same subnet.'];
        if ($this->overlapsExistingAddress($network, $discovery['existing_addresses'] ?? [])) return ['success' => false, 'message' => 'The selected customer subnet overlaps an existing router address. Select a different isolated IPv4 subnet.'];

        $dns = array_values(array_filter(array_map('trim', explode(',', (string) ($input['dns_servers'] ?? '1.1.1.1,8.8.8.8')))));
        if ($dns === [] || count(array_filter($dns, fn (string $ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)) > 0) return ['success' => false, 'message' => 'Use one or more valid IPv4 DNS servers separated by commas.'];

        $portalEnabled = (bool) ($input['enable_captive_portal'] ?? false);
        $portal = ['enabled' => false];
        if ($portalEnabled) {
            $portalVlan = (int) ($input['portal_vlan_id'] ?? 0);
            $portalGateway = (string) ($input['portal_gateway_cidr'] ?? '');
            $portalPool = (string) ($input['portal_dhcp_pool'] ?? '');
            if ($portalVlan < 2 || $portalVlan > 4094 || $portalVlan === $customerVlan || !$this->networkForCidr($portalGateway) || !$this->validPool($portalPool, $portalGateway)) {
                return ['success' => false, 'message' => 'Captive Portal requires a different VLAN ID plus a valid isolated IPv4 gateway and DHCP pool.'];
            }
            $portalNetwork = $this->networkForCidr($portalGateway);
            if ($portalNetwork === null || $this->cidrsOverlap($network, $portalNetwork) || $this->overlapsExistingAddress($portalNetwork, $discovery['existing_addresses'] ?? [])) {
                return ['success' => false, 'message' => 'The Captive Portal subnet must be isolated from the customer VLAN and every existing router subnet.'];
            }
            $portal = ['enabled' => true, 'vlan_id' => $portalVlan, 'gateway_cidr' => $portalGateway, 'network_cidr' => $portalNetwork, 'dhcp_pool' => $portalPool];
        }

        $names = [
            'customer_vlan' => "solarnet-customer-vlan-{$customerVlan}",
            'customer_pool' => "solarnet-customer-pool-{$customerVlan}",
            'customer_dhcp' => "solarnet-customer-dhcp-{$customerVlan}",
            'portal_vlan' => $portalEnabled ? "solarnet-portal-vlan-{$portal['vlan_id']}" : null,
            'portal_pool' => $portalEnabled ? "solarnet-portal-pool-{$portal['vlan_id']}" : null,
            'portal_dhcp' => $portalEnabled ? "solarnet-portal-dhcp-{$portal['vlan_id']}" : null,
            'portal_profile' => $portalEnabled ? "solarnet-portal-profile-{$portal['vlan_id']}" : null,
            'portal_hotspot' => $portalEnabled ? "solarnet-portal-hotspot-{$portal['vlan_id']}" : null,
        ];

        $createNat = ((int) ($discovery['counts']['firewall_nat'] ?? 0)) === 0;
        $qosMode = ($discovery['fq_codel_available'] ?? false) ? 'safe_compatible' : 'disabled_missing_fq_codel';
        $actions = [
            "Create isolated IPoE customer VLAN {$customerVlan} on {$parent}.",
            "Create DHCP pool, DHCP server, and DHCP network for {$gateway}.",
            'Install SolarNet billing suspension/payment-only firewall infrastructure; no customer address is added.',
            'Preserve existing API account, FastTrack, firewall, mangle, routing, bridge, WireGuard, and customer records.',
            'Do not create PPPoE, PPP profiles, PPP secrets, customer queues, customer static leases, or customer records.',
            $createNat ? "Create one SolarNet-owned masquerade NAT rule for {$wan}." : 'Preserve the compatible existing default NAT policy.',
            $qosMode === 'safe_compatible' ? 'Mark the router Safe QoS compatible; customer Simple Queues are created later by Billing.' : 'Leave QoS disabled until FQ-CoDel capability is available.',
        ];
        if ($portalEnabled) $actions[] = "Create isolated Captive Portal VLAN {$portal['vlan_id']} only; it does not convert IPoE customers to PPPoE or place the customer VLAN behind HotSpot.";

        return ['success' => true, 'data' => [
            'kind' => 'solarnet_clean_ipoe_provisioning_v1',
            'access' => 'IPoE ONLY',
            'pppoe' => 'NOT USED',
            'wan_interface' => $wan,
            'customer_parent_interface' => $parent,
            'customer_vlan_id' => $customerVlan,
            'customer_gateway_cidr' => $gateway,
            'customer_network_cidr' => $network,
            'customer_dhcp_pool' => $pool,
            'dns_servers' => $dns,
            'create_nat' => $createNat,
            'qos_mode' => $qosMode,
            'fasttrack' => ($discovery['fasttrack_enabled'] ?? false) ? 'PRESERVED / Full QoS disabled' : 'PRESERVED / Safe QoS ready',
            'captive_portal' => $portal,
            'resource_names' => $names,
            'planned_changes' => $actions,
        ]];
    }

    private function applyScript(array $plan): string
    {
        $n = $plan['resource_names'];
        $q = fn (string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        $comment = fn (string $resource): string => $q('SolarNet Provisioning: ' . $resource);
        $script = [
            ':log info "[SOLARNET] Starting approved clean IPoE provisioning"',
            "/interface vlan\n:if ([:len [find where name={$q($n['customer_vlan'])}]] = 0) do={ add name={$q($n['customer_vlan'])} interface={$q($plan['customer_parent_interface'])} vlan-id={$plan['customer_vlan_id']} comment={$comment('customer VLAN')} }",
            "/ip address\n:if ([:len [find where comment={$comment('customer gateway')}]] = 0) do={ add address={$q($plan['customer_gateway_cidr'])} interface={$q($n['customer_vlan'])} comment={$comment('customer gateway')} }",
            "/ip pool\n:if ([:len [find where name={$q($n['customer_pool'])}]] = 0) do={ add name={$q($n['customer_pool'])} ranges={$q($plan['customer_dhcp_pool'])} comment={$comment('customer pool')} }",
            "/ip dhcp-server\n:if ([:len [find where name={$q($n['customer_dhcp'])}]] = 0) do={ add name={$q($n['customer_dhcp'])} interface={$q($n['customer_vlan'])} address-pool={$q($n['customer_pool'])} lease-time=30m disabled=no comment={$comment('customer DHCP')} }",
            "/ip dhcp-server network\n:if ([:len [find where comment={$comment('customer DHCP network')}]] = 0) do={ add address={$q($plan['customer_network_cidr'])} gateway={$q(explode('/', $plan['customer_gateway_cidr'])[0])} dns-server={$q(implode(',', $plan['dns_servers']))} comment={$comment('customer DHCP network')} }",
        ];
        if ($plan['create_nat']) $script[] = "/ip firewall nat\n:if ([:len [find where comment={$comment('customer NAT')}]] = 0) do={ add chain=srcnat out-interface={$q($plan['wan_interface'])} action=masquerade comment={$comment('customer NAT')} }";

        if (($plan['captive_portal']['enabled'] ?? false) === true) {
            $portal = $plan['captive_portal'];
            $script[] = "/interface vlan\n:if ([:len [find where name={$q($n['portal_vlan'])}]] = 0) do={ add name={$q($n['portal_vlan'])} interface={$q($plan['customer_parent_interface'])} vlan-id={$portal['vlan_id']} comment={$comment('portal VLAN')} }";
            $script[] = "/ip address\n:if ([:len [find where comment={$comment('portal gateway')}]] = 0) do={ add address={$q($portal['gateway_cidr'])} interface={$q($n['portal_vlan'])} comment={$comment('portal gateway')} }";
            $script[] = "/ip pool\n:if ([:len [find where name={$q($n['portal_pool'])}]] = 0) do={ add name={$q($n['portal_pool'])} ranges={$q($portal['dhcp_pool'])} comment={$comment('portal pool')} }";
            $script[] = "/ip dhcp-server\n:if ([:len [find where name={$q($n['portal_dhcp'])}]] = 0) do={ add name={$q($n['portal_dhcp'])} interface={$q($n['portal_vlan'])} address-pool={$q($n['portal_pool'])} lease-time=30m disabled=no comment={$comment('portal DHCP')} }";
            $script[] = "/ip dhcp-server network\n:if ([:len [find where comment={$comment('portal DHCP network')}]] = 0) do={ add address={$q($portal['network_cidr'])} gateway={$q(explode('/', $portal['gateway_cidr'])[0])} dns-server={$q(implode(',', $plan['dns_servers']))} comment={$comment('portal DHCP network')} }";
            $script[] = "/ip hotspot profile\n:if ([:len [find where name={$q($n['portal_profile'])}]] = 0) do={ add name={$q($n['portal_profile'])} hotspot-address={$q(explode('/', $portal['gateway_cidr'])[0])} login-by=http-pap comment={$comment('portal profile')} }";
            $script[] = "/ip hotspot\n:if ([:len [find where name={$q($n['portal_hotspot'])}]] = 0) do={ add name={$q($n['portal_hotspot'])} interface={$q($n['portal_vlan'])} profile={$q($n['portal_profile'])} disabled=no comment={$comment('isolated captive portal')} }";
        }
        $script[] = ':put "[SOLARNET] IPoE base provisioning script completed"';
        return implode("\n\n", $script);
    }

    private function rollbackScript(array $plan): string
    {
        $n = $plan['resource_names'];
        $q = fn (string $value): string => '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        $comment = fn (string $resource): string => $q('SolarNet Provisioning: ' . $resource);
        $script = [':log warning "[SOLARNET] Rolling back owned clean-provisioning resources"'];
        if (($plan['captive_portal']['enabled'] ?? false) === true) {
            $script[] = "/ip hotspot remove [find where name={$q($n['portal_hotspot'])}]";
            $script[] = "/ip hotspot profile remove [find where name={$q($n['portal_profile'])}]";
            $script[] = "/ip dhcp-server remove [find where name={$q($n['portal_dhcp'])}]";
            $script[] = "/ip dhcp-server network remove [find where comment={$comment('portal DHCP network')}]";
            $script[] = "/ip pool remove [find where name={$q($n['portal_pool'])}]";
            $script[] = "/ip address remove [find where comment={$comment('portal gateway')}]";
            $script[] = "/interface vlan remove [find where name={$q($n['portal_vlan'])}]";
        }
        $script[] = "/ip dhcp-server remove [find where name={$q($n['customer_dhcp'])}]";
        $script[] = "/ip dhcp-server network remove [find where comment={$comment('customer DHCP network')}]";
        $script[] = "/ip pool remove [find where name={$q($n['customer_pool'])}]";
        $script[] = "/ip address remove [find where comment={$comment('customer gateway')}]";
        $script[] = "/interface vlan remove [find where name={$q($n['customer_vlan'])}]";
        if ($plan['create_nat']) $script[] = "/ip firewall nat remove [find where comment={$comment('customer NAT')}]";
        $script[] = ':put "[SOLARNET] Owned clean-provisioning resources removed"';
        return implode("\n", $script);
    }

    private function networkForCidr(string $cidr): ?string
    {
        [$ip, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $prefix === null || !ctype_digit($prefix) || (int) $prefix < 8 || (int) $prefix > 30) return null;
        $mask = -1 << (32 - (int) $prefix);
        return long2ip(ip2long($ip) & $mask) . '/' . $prefix;
    }

    private function validPool(string $pool, string $gatewayCidr): bool
    {
        $parts = explode('-', $pool, 2);
        if (count($parts) !== 2 || !filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || !filter_var($parts[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $network = $this->networkForCidr($gatewayCidr);
        if (!$network) return false;
        [$networkIp, $prefix] = explode('/', $network);
        $mask = -1 << (32 - (int) $prefix);
        $start = ip2long($parts[0]);
        $end = ip2long($parts[1]);
        $networkLong = ip2long($networkIp);
        $gatewayLong = ip2long(explode('/', $gatewayCidr)[0]);
        $broadcast = $networkLong | ~$mask;
        return ($start & $mask) === $networkLong
            && ($end & $mask) === $networkLong
            && $start > $networkLong
            && $end < $broadcast
            && $start <= $end
            && !($gatewayLong >= $start && $gatewayLong <= $end);
    }

    private function overlapsExistingAddress(string $candidateNetwork, array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (is_string($address) && $this->cidrsOverlap($candidateNetwork, $address)) return true;
        }
        return false;
    }

    private function cidrsOverlap(string $left, string $right): bool
    {
        $leftNetwork = $this->networkForCidr($left);
        $rightNetwork = $this->networkForCidr($right);
        if ($leftNetwork === null || $rightNetwork === null) return false;
        [$leftIp, $leftPrefix] = explode('/', $leftNetwork);
        [$rightIp, $rightPrefix] = explode('/', $rightNetwork);
        $prefix = min((int) $leftPrefix, (int) $rightPrefix);
        $mask = -1 << (32 - $prefix);
        return (ip2long($leftIp) & $mask) === (ip2long($rightIp) & $mask);
    }
}
