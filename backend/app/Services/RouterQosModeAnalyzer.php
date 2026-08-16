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
            ],
            'safe' => [
                'available' => $safeAvailable,
                'queue_type' => $fqCodel[0] ?? null,
                'managed_queue_count' => (int) ($queues['billing_customer_queues'] ?? 0),
                'ownership' => 'Only queues named customer-{customer UUID} that also match the customer router and IP address can be changed.',
                'reasons' => $safeReasons,
            ],
            'disabled' => [
                'available' => !$safeAvailable,
                'reason' => $safeAvailable ? null : implode(' ', $safeReasons),
            ],
        ];
    }
}
