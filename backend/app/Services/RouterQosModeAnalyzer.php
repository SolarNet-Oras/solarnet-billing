<?php

namespace App\Services;

/**
 * Determines the safest QoS mode from a read-only RouterOS inspection.
 *
 * This class is intentionally conservative. A false negative means SolarNet
 * leaves a router unchanged; a false positive could affect production traffic.
 */
class RouterQosModeAnalyzer
{
    public function analyze(array $inspection): array
    {
        $interfaces = array_values(array_filter(array_map(
            fn (array $item) => (($item['running'] ?? false) && !($item['disabled'] ?? false)) ? ($item['name'] ?? null) : null,
            $inspection['interfaces'] ?? [],
        )));
        $clientInterfaces = array_values(array_unique(array_filter($inspection['client_interfaces'] ?? [])));
        $wanCandidates = array_values(array_filter(array_map(fn (array $wan) => $wan['interface'] ?? null, $inspection['wan_candidates'] ?? [])));
        $queues = $inspection['existing_queues'] ?? [];
        $capabilities = $inspection['queue_capabilities'] ?? [];
        $fqCodel = array_values($capabilities['fq_codel'] ?? []);

        $fullReasons = [];
        if (($inspection['fasttrack']['enabled'] ?? false) === true) $fullReasons[] = 'FastTrack is enabled and SolarNet will not alter an administrator-owned FastTrack rule.';
        if (($inspection['cpu_load'] ?? 0) >= 80) $fullReasons[] = 'Router CPU is at or above 80%.';
        if (($queues['solarnet_qos_trees'] ?? 0) > 0) $fullReasons[] = 'SolarNet-owned QoS trees already exist; the system will not stack another global policy.';
        if (($inspection['mangle_rule_count'] ?? 0) > 0) $fullReasons[] = 'Existing mangle rules prevent SolarNet from proving that global packet flow will not conflict.';
        if (count($clientInterfaces) !== 1) $fullReasons[] = 'Required global client parent cannot be safely determined because multiple client-facing DHCP/VLAN interfaces were detected.';
        if (($inspection['multi_wan_detected'] ?? false) === true || count($wanCandidates) !== 1) $fullReasons[] = 'Required global WAN parent cannot be safely determined from the active routing configuration.';
        if ($clientInterfaces !== [] && array_diff($clientInterfaces, $interfaces) !== []) $fullReasons[] = 'A detected client interface is not a confirmed running RouterOS interface.';
        if ($wanCandidates !== [] && array_diff($wanCandidates, $interfaces) !== []) $fullReasons[] = 'The detected WAN route does not resolve to a confirmed running RouterOS interface.';
        if ($fqCodel === []) $fullReasons[] = 'FQ-CoDel is not available on this RouterOS device.';
        if (($queues['other_simple_queues'] ?? 0) > 0) $fullReasons[] = 'Administrator-created Simple Queues are present, so a global QoS architecture is not assumed compatible.';

        // An interface-wide queue tree cannot be tested against a single
        // customer without adding marks/routing changes. That would violate the
        // read -> analyse -> controlled test safety contract, so it remains
        // unavailable until an isolated full-QoS test implementation exists.
        $fullSafetyPassed = $fullReasons === [];
        if ($fullSafetyPassed) $fullReasons[] = 'Full QoS requires an isolated, topology-proven test target. SolarNet will not create packet marks or alter routing merely to make that possible.';

        $safeReasons = [];
        if (($inspection['cpu_load'] ?? 0) >= 80) $safeReasons[] = 'Router CPU is at or above 80%; a queue optimization test would not be safe.';
        if (($queues['billing_customer_queues'] ?? 0) < 1) $safeReasons[] = 'No SolarNet-managed customer Simple Queue was detected.';
        if ($fqCodel === []) $safeReasons[] = 'FQ-CoDel is not available for a controlled existing-queue optimization.';
        if (($queues['solarnet_qos_trees'] ?? 0) > 0) $safeReasons[] = 'An existing SolarNet QoS deployment must be reviewed or rolled back before a Safe QoS test.';

        $safeAvailable = $safeReasons === [];

        return [
            'recommended_mode' => $safeAvailable ? 'safe' : 'disabled',
            'full' => [
                'available' => false,
                'safety_passed' => $fullSafetyPassed,
                'test_available' => false,
                'reasons' => $fullReasons,
                'suggestions' => $this->suggestionsFor($fullReasons, 'full'),
            ],
            'safe' => [
                'available' => $safeAvailable,
                'queue_type' => $fqCodel[0] ?? null,
                'managed_queue_count' => (int) ($queues['billing_customer_queues'] ?? 0),
                'ownership' => 'Only queues named customer-{customer UUID} that also match the customer router and IP address can be changed.',
                'reasons' => $safeReasons,
                'suggestions' => $this->suggestionsFor($safeReasons, 'safe'),
            ],
            'disabled' => [
                'available' => !$safeAvailable,
                'reason' => $safeAvailable ? null : implode(' ', $safeReasons),
            ],
        ];
    }

    /**
     * Return operator guidance only. These are never commands and the QoS
     * workflow never attempts to remove, disable, or rewrite the listed
     * administrator-owned RouterOS configuration.
     */
    private function suggestionsFor(array $reasons, string $mode): array
    {
        $suggestions = [];

        foreach ($reasons as $reason) {
            if (str_contains($reason, 'CPU')) {
                $suggestions[] = 'Wait for router CPU to remain below 80%, then inspect again. Investigate traffic load separately; SolarNet will not change running services to lower CPU.';
            }
            if (str_contains($reason, 'No SolarNet-managed customer Simple Queue')) {
                $suggestions[] = 'Sync the customer from Billing to create or verify its SolarNet-managed Simple Queue. Do not rename or adopt an unknown administrator queue.';
            }
            if (str_contains($reason, 'FQ-CoDel is not available')) {
                $suggestions[] = 'Keep QoS disabled on this router. Review RouterOS and hardware compatibility in a maintenance window before considering an upgrade; no automatic upgrade is performed.';
            }
            if (str_contains($reason, 'SolarNet QoS deployment')) {
                $suggestions[] = 'Review the existing SolarNet QoS deployment and its backup. Roll it back only if an administrator confirms that it is no longer required.';
            }
            if (str_contains($reason, 'FastTrack')) {
                $suggestions[] = 'Keep the administrator-owned FastTrack rule unchanged. Use Safe QoS, or have a network administrator design and test a documented FastTrack/QoS policy in a maintenance window.';
            }
            if (str_contains($reason, 'mangle rules')) {
                $suggestions[] = 'Do not remove or edit existing mangle rules. Have a network administrator review their packet-flow purpose before any separate Full QoS design is considered.';
            }
            if (str_contains($reason, 'multiple client-facing DHCP/VLAN interfaces')) {
                $suggestions[] = 'Do not merge or change VLANs. Use Safe QoS per verified customer queue; a global design requires a documented per-VLAN topology and maintenance test plan.';
            }
            if (str_contains($reason, 'WAN parent')) {
                $suggestions[] = 'Keep multi-WAN, routing, and failover unchanged. A network administrator must document a single testable WAN path before a separate global QoS design can be reviewed.';
            }
            if (str_contains($reason, 'not a confirmed running RouterOS interface')) {
                $suggestions[] = 'Confirm the interface state and routing with the network administrator, then inspect again. SolarNet will not enable interfaces automatically.';
            }
            if (str_contains($reason, 'Administrator-created Simple Queues')) {
                $suggestions[] = 'Keep administrator-created queues unchanged. Use Safe QoS only on the verified SolarNet customer queue or document a separate compatible queue design.';
            }
        }

        if ($mode === 'full') {
            $suggestions[] = 'Full global QoS remains intentionally unavailable until an isolated, topology-proven test can be implemented without changing marks, routing, VLANs, or administrator rules. Safe QoS is the supported production path when eligible.';
        }

        return array_values(array_unique($suggestions));
    }
}
