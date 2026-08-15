<?php

namespace App\Services;

/**
 * Pure QoS preview logic. It does not speak to RouterOS and never changes a
 * customer plan or queue. Keeping it separate makes every refusal testable.
 */
class RouterQosPlanner
{
    public function plan(array $inspection, array $input): array
    {
        $errors = [];
        $warnings = $inspection['warnings'] ?? [];
        $interfaces = array_values(array_filter(array_map(fn (array $item) => $item['name'] ?? null, $inspection['interfaces'] ?? [])));
        $downloadParent = trim((string) ($input['download_parent'] ?? ''));
        $uploadParent = trim((string) ($input['upload_parent'] ?? ''));
        $downloadCapacity = (float) ($input['download_capacity_mbps'] ?? 0);
        $uploadCapacity = (float) ($input['upload_capacity_mbps'] ?? 0);
        $ceilingPercent = (float) ($input['ceiling_percent'] ?? 95);
        $mode = (string) ($input['mode'] ?? 'production');

        if (!in_array($mode, ['production', 'test'], true)) $errors[] = 'QoS mode must be production or test.';
        if ($mode === 'test') $errors[] = 'Test mode is intentionally refused for interface-wide queue trees. Select a dedicated, isolated test interface before a test-mode deploy is introduced.';
        if (($inspection['fasttrack']['enabled'] ?? false) === true) $errors[] = 'FastTrack is enabled. SolarNet will not automatically disable or alter an administrator-owned FastTrack rule.';
        if (($inspection['existing_queues']['solarnet_qos_trees'] ?? 0) > 0) $errors[] = 'SolarNet QoS already exists on this router. Review or roll back the active deployment before creating another one.';
        if (($inspection['cpu_load'] ?? 0) >= 80) $errors[] = 'Router CPU is already at or above 80%. QoS deployment is blocked to protect client connectivity.';
        if ($downloadCapacity <= 0 || $uploadCapacity <= 0) $errors[] = 'Enter separately measured usable download and upload capacities in Mbps.';
        if ($downloadCapacity > 100000 || $uploadCapacity > 100000) $errors[] = 'Capacity is outside the safe range for this QoS workflow.';
        if ($ceilingPercent < 50 || $ceilingPercent > 99) $errors[] = 'The shaping ceiling must be between 50% and 99% of measured usable capacity.';
        if ($downloadParent === '' || !in_array($downloadParent, $interfaces, true)) $errors[] = 'Select a valid client-facing download parent interface discovered from this router.';
        if ($uploadParent === '' || !in_array($uploadParent, $interfaces, true)) $errors[] = 'Select a valid WAN upload parent interface discovered from this router.';
        if ($downloadParent !== '' && $downloadParent === $uploadParent) $errors[] = 'Download and upload parents must be different interfaces to avoid conflicting tree shaping.';

        $capabilities = $inspection['queue_capabilities'] ?? [];
        $fqCodel = array_values($capabilities['fq_codel'] ?? []);
        $pcq = array_values($capabilities['pcq'] ?? []);
        $cake = array_values($capabilities['cake'] ?? []);
        $selectedQueueType = $fqCodel[0] ?? $pcq[0] ?? null;
        $strategy = $fqCodel !== [] ? 'fq_codel_interface_tree' : ($pcq !== [] ? 'pcq_interface_tree' : null);
        if ($selectedQueueType === null) $errors[] = 'Neither FQ-CoDel nor PCQ is available on this RouterOS device.';

        if (($inspection['multi_wan_detected'] ?? false) === true) {
            $knownWanInterfaces = array_values(array_filter(array_map(fn (array $wan) => $wan['interface'] ?? null, $inspection['wan_candidates'] ?? [])));
            if ($knownWanInterfaces === [] || !in_array($uploadParent, $knownWanInterfaces, true)) {
                $errors[] = 'Multiple WAN/default routes are present. The selected upload parent must be a positively identified WAN interface; no global multi-WAN queue is created.';
            }
        }

        $downloadLimit = $this->routerRate($downloadCapacity * ($ceilingPercent / 100));
        $uploadLimit = $this->routerRate($uploadCapacity * ($ceilingPercent / 100));
        $configuration = [
            'mode' => $mode,
            'download_capacity_mbps' => $downloadCapacity,
            'upload_capacity_mbps' => $uploadCapacity,
            'ceiling_percent' => $ceilingPercent,
            'download_parent' => $downloadParent,
            'upload_parent' => $uploadParent,
            'download_limit' => $downloadLimit,
            'upload_limit' => $uploadLimit,
            'queue_type' => $selectedQueueType,
            'strategy' => $strategy,
        ];

        return [
            'ready' => $errors === [],
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'configuration' => $configuration,
            'recommendation' => [
                'strategy' => $strategy,
                'queue_type' => $selectedQueueType,
                'cake_available_but_not_selected' => $cake,
                'reason' => $fqCodel !== []
                    ? 'FQ-CoDel is selected as the conservative low-latency default. Existing customer Simple Queue plan limits remain authoritative.'
                    : ($pcq !== [] ? 'PCQ is the safe RouterOS fallback because FQ-CoDel is unavailable.' : 'No safe supported queue type was detected.'),
            ],
            'preservation' => [
                'customer_simple_queues_preserved' => $inspection['existing_queues']['billing_customer_queues'] ?? 0,
                'administrator_simple_queues_preserved' => $inspection['existing_queues']['other_simple_queues'] ?? 0,
                'firewall_rules_changed' => 0,
                'mangle_rules_changed' => 0,
                'queue_types_created' => 0,
                'queue_trees_to_create' => $errors === [] ? 2 : 0,
            ],
            'risk' => $errors !== [] ? 'blocked' : (($inspection['multi_wan_detected'] ?? false) ? 'medium' : 'low'),
        ];
    }

    private function routerRate(float $mbps): string
    {
        return rtrim(rtrim(number_format($mbps, 2, '.', ''), '0'), '.') . 'M';
    }
}
