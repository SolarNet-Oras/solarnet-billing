<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use App\Models\RouterProvisioningAudit;
use App\Models\RouterQosDeployment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class RouterQosService
{
    public function __construct(
        private readonly MikrotikService $mikrotikService,
        private readonly RouterQosPlanner $planner,
        private readonly RouterQosModeAnalyzer $modeAnalyzer,
    ) {
    }

    public function status(Router $router): array
    {
        $inspection = $this->mikrotikService->qosInspection($router);
        if (!$inspection['success']) return $inspection;
        $inspection['data'] = $this->withVerifiedProvisioningTopology($router, $inspection['data']);

        $active = RouterQosDeployment::query()->where('router_id', $router->id)->where('status', 'active')->latest('applied_at')->first();
        return [
            'success' => true,
            'data' => [
                'inspection' => $inspection['data'],
                'analysis' => $this->modeAnalyzer->analyze($inspection['data']),
                'active_deployment' => $active,
            ],
        ];
    }

    /** Use only a successfully verified provisioning plan as explicit topology evidence. */
    private function withVerifiedProvisioningTopology(Router $router, array $inspection): array
    {
        $audit = RouterProvisioningAudit::query()
            ->where('router_id', $router->id)
            ->where('status', 'verified_pending_ipoe_client_test')
            ->whereNotNull('verified_at')
            ->latest('verified_at')
            ->first();
        $plan = $audit?->plan;
        if (!is_array($plan)) return $inspection;

        $wan = (string) ($plan['wan_interface'] ?? '');
        $customerInterface = (string) ($plan['resource_names']['customer_vlan'] ?? '');
        if ($wan === '' || $customerInterface === '') return $inspection;

        $inspection['verified_provisioning_topology'] = [
            'audit_id' => $audit->id,
            'wan_interface' => $wan,
            'customer_interface' => $customerInterface,
            'customer_parent_interface' => $plan['customer_parent_interface'] ?? null,
            'verified_at' => $audit->verified_at?->toIso8601String(),
        ];
        return $inspection;
    }

    public function configurations(Router $router): array
    {
        return [
            'success' => true,
            'data' => RouterQosDeployment::query()
                ->where('router_id', $router->id)
                ->with(['creator:id,name,email', 'applier:id,name,email', 'rollbackUser:id,name,email'])
                ->latest('created_at')
                ->limit(20)
                ->get(),
        ];
    }

    /** Read existing customer queues for visibility; never changes their limits. */
    public function clients(Router $router): array
    {
        $customers = Customer::query()
            ->where('router_id', $router->id)
            ->with('servicePlan:id,name,download_speed,upload_speed,priority')
            ->orderBy('full_name')
            ->get();
        $queueResult = $this->mikrotikService->getQueues($router);
        $queues = $queueResult['success'] ? collect($queueResult['data'])->keyBy('name') : collect();

        return [
            'success' => true,
            'data' => $customers->map(function (Customer $customer) use ($queues): array {
                $queue = $queues->get('customer-' . $customer->id, []);
                $safeReason = null;
                $safeEligible = false;
                if ($customer->status !== 'active') {
                    $safeReason = 'Only an active client can be used for a controlled Safe QoS test.';
                } elseif ($queue === []) {
                    $safeReason = 'SolarNet-managed customer queue was not found.';
                } elseif (!$this->queueTargetsCustomer($queue['target'] ?? null, $customer->ip_address)) {
                    $safeReason = 'Queue target does not exactly match the current customer IP.';
                } elseif (($queue['disabled'] ?? 'false') === 'true') {
                    $safeReason = 'The customer queue is disabled.';
                } elseif (empty($queue['max-limit'])) {
                    $safeReason = 'The customer queue has no existing maximum limit to preserve.';
                } else {
                    $safeEligible = true;
                }
                return [
                    'customer_id' => $customer->id,
                    'account_number' => $customer->account_number,
                    'full_name' => $customer->full_name,
                    'ip_address' => $customer->ip_address,
                    'mac_address' => $customer->mac_address,
                    'status' => $customer->status,
                    'plan' => $customer->servicePlan ? [
                        'name' => $customer->servicePlan->name,
                        'download_speed' => $customer->servicePlan->download_speed,
                        'upload_speed' => $customer->servicePlan->upload_speed,
                        'priority' => $customer->servicePlan->priority,
                        'qos_priority_level' => match (true) {
                            $customer->servicePlan->priority <= 2 => 'Critical',
                            $customer->servicePlan->priority <= 4 => 'High',
                            $customer->servicePlan->priority <= 6 => 'Normal',
                            default => 'Low',
                        },
                    ] : null,
                    'queue' => $queue === [] ? null : [
                        'name' => $queue['name'] ?? null,
                        'max_limit' => $queue['max-limit'] ?? null,
                        'rate' => $queue['rate'] ?? null,
                        'dropped' => $queue['dropped'] ?? null,
                        'disabled' => ($queue['disabled'] ?? 'false') === 'true',
                    ],
                    'safe_qos' => ['eligible' => $safeEligible, 'reason' => $safeReason],
                ];
            })->values(),
            'queue_read_warning' => $queueResult['success'] ? null : ($queueResult['message'] ?? 'Router queue read failed.'),
        ];
    }

    public function preview(Router $router, User $user, array $input): array
    {
        $inspection = $this->mikrotikService->qosInspection($router);
        if (!$inspection['success']) return $inspection;
        $inspection['data'] = $this->withVerifiedProvisioningTopology($router, $inspection['data']);

        $analysis = $this->modeAnalyzer->analyze($inspection['data']);
        if (!$analysis['full']['available']) {
            return ['success' => false, 'message' => 'Full QoS is not applicable on this router. ' . implode(' ', $analysis['full']['reasons']), 'data' => ['analysis' => $analysis]];
        }

        $plan = $this->planner->plan($inspection['data'], $input);
        $version = ((int) RouterQosDeployment::query()->where('router_id', $router->id)->max('configuration_version')) + 1;
        $deployment = RouterQosDeployment::create([
            'router_id' => $router->id,
            'configuration_version' => $version,
            'status' => $plan['ready'] ? 'previewed' : 'refused',
            'strategy' => $plan['configuration']['strategy'],
            'queue_type' => $plan['configuration']['queue_type'],
            'configuration' => $plan['configuration'],
            'inspection' => $inspection['data'],
            'failure_reason' => $plan['ready'] ? null : implode(' ', $plan['errors']),
            'created_by' => $user->id,
        ]);

        return [
            'success' => true,
            'data' => ['deployment' => $deployment, 'preview' => $plan],
            'message' => $plan['ready'] ? 'QoS preview is ready for administrator confirmation. No RouterOS change was made.' : 'QoS preview was refused. No RouterOS change was made.',
        ];
    }

    /**
     * Prepare one explicitly selected SolarNet customer queue for a controlled
     * Safe QoS test. This is a database preview only; it does not touch
     * RouterOS until startSafeTest is explicitly confirmed.
     */
    public function previewSafe(Router $router, User $user, array $input): array
    {
        $inspection = $this->mikrotikService->qosInspection($router);
        if (!$inspection['success']) return $inspection;
        $inspection['data'] = $this->withVerifiedProvisioningTopology($router, $inspection['data']);

        $analysis = $this->modeAnalyzer->analyze($inspection['data']);
        if (!$analysis['safe']['available']) {
            return [
                'success' => false,
                'code' => 'SAFE_QOS_NOT_APPLICABLE',
                'message' => 'Safe QoS cannot be deployed on this router. No RouterOS change was made. ' . implode(' ', $analysis['safe']['reasons']),
                'data' => ['analysis' => $analysis, 'deployment_blocked' => true],
            ];
        }

        $customer = Customer::query()->where('id', $input['customer_id'])->where('router_id', $router->id)->first();
        if (!$customer) return ['success' => false, 'message' => 'The selected client does not belong to this router.'];
        if ($customer->status !== 'active') return ['success' => false, 'message' => 'Only an active client may be selected for a controlled Safe QoS test.'];

        $queueResult = $this->mikrotikService->readManagedCustomerQueue($router, $customer);
        if (!$queueResult['success']) return $queueResult;
        $before = $queueResult['data'];
        if (empty($before['max-limit'])) return ['success' => false, 'message' => 'The selected SolarNet queue has no maximum limit to preserve.'];

        $queueType = $this->queueTypePair((string) $analysis['safe']['queue_type']);
        if ($queueType === '') return ['success' => false, 'message' => 'A safe FQ-CoDel queue type could not be determined.'];
        if (($before['queue'] ?? '') === $queueType) return ['success' => false, 'message' => 'The selected customer queue already uses the recommended FQ-CoDel discipline; no Safe QoS change is needed.'];

        $version = ((int) RouterQosDeployment::query()->where('router_id', $router->id)->max('configuration_version')) + 1;
        $deployment = RouterQosDeployment::create([
            'router_id' => $router->id,
            'configuration_version' => $version,
            'status' => 'previewed',
            'strategy' => 'safe_existing_simple_queue_fq_codel',
            'queue_type' => $queueType,
            'configuration' => [
                'qos_mode' => 'safe',
                'customer_id' => $customer->id,
                'customer_account_number' => $customer->account_number,
                'customer_name' => $customer->full_name,
                'queue_name' => $before['name'],
                'before' => $before,
                'test_duration_minutes' => (int) $input['test_duration_minutes'],
                'test_target' => $input['test_target'],
            ],
            'inspection' => $inspection['data'],
            'created_by' => $user->id,
        ]);

        return [
            'success' => true,
            'data' => [
                'deployment' => $deployment,
                'preview' => [
                    'ready' => true,
                    'mode' => 'safe',
                    'customer' => ['id' => $customer->id, 'account_number' => $customer->account_number, 'full_name' => $customer->full_name],
                    'queue_type_before' => $before['queue'] ?? null,
                    'queue_type_after' => $queueType,
                    'preserved' => ['max_limit' => $before['max-limit'], 'target' => $before['target'], 'parent' => $before['parent'] ?? null, 'packet_marks' => $before['packet-marks'] ?? null, 'priority' => $before['priority'] ?? null, 'comment' => $before['comment'] ?? null],
                    'test_duration_minutes' => (int) $input['test_duration_minutes'],
                    'message' => 'Safe QoS will change only this verified SolarNet customer queue discipline during the controlled test. Its maximum limit and all network configuration remain unchanged.',
                ],
            ],
            'message' => 'Safe QoS preview is ready. No RouterOS change has been made.',
        ];
    }

    /** Start a bounded test on exactly one pre-approved SolarNet-managed queue. */
    public function startSafeTest(Router $router, RouterQosDeployment $deployment, User $user): array
    {
        if ($deployment->router_id !== $router->id || $deployment->strategy !== 'safe_existing_simple_queue_fq_codel' || $deployment->status !== 'previewed') {
            return ['success' => false, 'message' => 'Only a current Safe QoS preview for this router can start a test.'];
        }

        $configuration = $deployment->configuration ?? [];
        $customer = Customer::query()->where('id', $configuration['customer_id'] ?? null)->where('router_id', $router->id)->first();
        if (!$customer || $customer->status !== 'active') return ['success' => false, 'message' => 'The Safe QoS test client is no longer an active customer on this router.'];

        $inspection = $this->mikrotikService->qosInspection($router);
        if (!$inspection['success']) return $inspection;
        $inspection['data'] = $this->withVerifiedProvisioningTopology($router, $inspection['data']);
        $analysis = $this->modeAnalyzer->analyze($inspection['data']);
        if (!$analysis['safe']['available']) {
            return [
                'success' => false,
                'code' => 'SAFE_QOS_NOT_APPLICABLE',
                'message' => 'Safe QoS can no longer be deployed on this router. No queue was changed. ' . implode(' ', $analysis['safe']['reasons']),
                'data' => ['analysis' => $analysis, 'deployment_blocked' => true],
            ];
        }

        $queueResult = $this->mikrotikService->readManagedCustomerQueue($router, $customer);
        if (!$queueResult['success']) return $queueResult;
        $before = $queueResult['data'];
        if (($before['name'] ?? null) !== ($configuration['queue_name'] ?? null) || empty($before['max-limit'])) {
            return ['success' => false, 'message' => 'The managed queue no longer matches the approved Safe QoS preview.'];
        }

        $baselineMetrics = $this->mikrotikService->qosMetrics($router);
        if (!$baselineMetrics['success']) return ['success' => false, 'message' => 'Safe QoS test was blocked because live router metrics could not be read. ' . ($baselineMetrics['message'] ?? '')];
        $baselinePing = $this->mikrotikService->qosPingTest($router, (string) $configuration['test_target']);
        if (!$baselinePing['success'] || (($baselinePing['data']['received'] ?? 0) < 1)) {
            return ['success' => false, 'message' => 'Safe QoS test was blocked because the selected router ping target did not return a baseline response.'];
        }

        $backup = $this->mikrotikService->createQosBackup($router, 'solarnet-safe-qos-v' . $deployment->configuration_version . '-' . now()->format('YmdHis'));
        if (!$backup['success']) return $backup;

        $result = $this->mikrotikService->applySafeQueueType($router, $customer, $before, (string) $deployment->queue_type);
        if (!$result['success']) {
            // A RouterOS set can fail after accepting part of a request. Always
            // attempt the exact captured queue restore before reporting a
            // failed Safe QoS start.
            $rollback = $this->mikrotikService->restoreManagedCustomerQueue($router, $customer, $before);
            $deployment->update([
                'status' => $rollback['success'] ? 'rolled_back' : 'failed',
                'backup_filename' => $backup['backup_file'],
                'backup_verified_at' => now(),
                'rolled_back_at' => $rollback['success'] ? now() : null,
                'failure_reason' => $result['message'] . ' Rollback: ' . ($rollback['message'] ?? 'not attempted'),
                'verification' => ['start_failure' => $result, 'rollback' => $rollback],
            ]);
            return ['success' => false, 'message' => $deployment->failure_reason, 'data' => $deployment->fresh()];
        }

        $configuration['before'] = $before;
        $configuration['test_baseline'] = ['metrics' => $baselineMetrics['data'], 'ping' => $baselinePing['data']];
        $deployment->update([
            'status' => 'safe_testing',
            'configuration' => $configuration,
            'inspection' => $inspection['data'],
            'backup_filename' => $backup['backup_file'],
            'backup_verified_at' => now(),
            'test_started_at' => now(),
            'test_expires_at' => now()->addMinutes((int) $configuration['test_duration_minutes']),
            'failure_reason' => null,
        ]);

        return ['success' => true, 'data' => $deployment->fresh(), 'message' => "Safe QoS test started for {$customer->full_name}. Only {$before['name']} was updated; its {$before['max-limit']} limit remains unchanged. The scheduler will verify it at the end of the test and automatically restore it if the test fails."];
    }

    /** Finalize an expired Safe QoS test. Any failed verification restores the original queue. */
    public function completeSafeTest(RouterQosDeployment $deployment): array
    {
        if ($deployment->status !== 'safe_testing') return ['success' => false, 'message' => 'This deployment is not an active Safe QoS test.'];
        $configuration = $deployment->configuration ?? [];
        $router = $deployment->router;
        $customer = Customer::query()->where('id', $configuration['customer_id'] ?? null)->where('router_id', $deployment->router_id)->first();
        if (!$router || !$customer || !is_array($configuration['before'] ?? null)) {
            return $this->failSafeTestAndRestore($deployment, $router, $customer, 'The Safe QoS test record no longer has a valid router, customer, or original queue snapshot.');
        }

        $queueResult = $this->mikrotikService->readManagedCustomerQueue($router, $customer);
        $metrics = $this->mikrotikService->qosMetrics($router);
        $ping = $this->mikrotikService->qosPingTest($router, (string) ($configuration['test_target'] ?? '1.1.1.1'));
        $failures = [];
        if (!$queueResult['success']) $failures[] = $queueResult['message'] ?? 'The Safe QoS queue cannot be read.';
        if ($queueResult['success'] && (($queueResult['data']['queue'] ?? null) !== $deployment->queue_type)) $failures[] = 'The tested queue type is no longer the verified Safe QoS queue type.';
        if (!$metrics['success']) $failures[] = $metrics['message'] ?? 'Live router metrics cannot be read.';
        if (($metrics['data']['cpu_load'] ?? 0) >= 80) $failures[] = 'Router CPU reached 80% or higher during the Safe QoS test.';
        if (!$ping['success'] || (($ping['data']['received'] ?? 0) < 1)) $failures[] = 'The router ping target stopped responding during the Safe QoS test.';

        $baselinePing = $configuration['test_baseline']['ping'] ?? [];
        if (($baselinePing['received'] ?? 0) > 0 && (($ping['data']['packet_loss_percent'] ?? 100) > (($baselinePing['packet_loss_percent'] ?? 0) + 20))) {
            $failures[] = 'Packet loss increased by more than 20 percentage points during the Safe QoS test.';
        }

        if ($failures !== []) return $this->failSafeTestAndRestore($deployment, $router, $customer, implode(' ', $failures), ['metrics' => $metrics['data'] ?? null, 'ping' => $ping['data'] ?? null]);

        $deployment->update([
            'status' => 'safe_test_passed',
            'test_completed_at' => now(),
            'verification' => array_merge($deployment->verification ?? [], ['safe_test' => ['passed' => true, 'metrics' => $metrics['data'], 'ping' => $ping['data']]]),
        ]);
        return ['success' => true, 'message' => 'Safe QoS test passed. Administrator confirmation is required to retain this single customer queue optimization.', 'data' => $deployment->fresh()];
    }

    public function completeExpiredSafeTests(): array
    {
        $deployments = RouterQosDeployment::query()->with('router')->where('status', 'safe_testing')->whereNotNull('test_expires_at')->where('test_expires_at', '<=', now())->get();
        $result = ['checked' => $deployments->count(), 'passed' => 0, 'rolled_back' => 0, 'errors' => []];
        foreach ($deployments as $deployment) {
            $completed = $this->completeSafeTest($deployment);
            if ($completed['success']) $result['passed']++;
            elseif (($deployment->fresh()?->status ?? null) === 'rolled_back') $result['rolled_back']++;
            else $result['errors'][] = ['deployment_id' => $deployment->id, 'message' => $completed['message'] ?? 'Safe QoS test could not be completed.'];
        }
        return $result;
    }

    /** Retain the already-tested one-queue optimization after administrator approval. */
    public function applySafe(Router $router, RouterQosDeployment $deployment, User $user): array
    {
        if ($deployment->router_id !== $router->id || $deployment->strategy !== 'safe_existing_simple_queue_fq_codel' || $deployment->status !== 'safe_test_passed') {
            return ['success' => false, 'message' => 'Only a Safe QoS deployment with a passed controlled test can be applied.'];
        }
        $configuration = $deployment->configuration ?? [];
        $customer = Customer::query()->where('id', $configuration['customer_id'] ?? null)->where('router_id', $router->id)->first();
        $queueResult = $customer ? $this->mikrotikService->readManagedCustomerQueue($router, $customer) : ['success' => false];
        if (!$customer || !$queueResult['success'] || (($queueResult['data']['queue'] ?? null) !== $deployment->queue_type)) {
            return ['success' => false, 'message' => 'The tested Safe QoS queue is no longer verified. It was not retained.'];
        }

        $deployment->update(['status' => 'active', 'applied_by' => $user->id, 'applied_at' => now()]);
        return ['success' => true, 'message' => 'Safe QoS was approved for this one tested SolarNet customer queue. No VLAN, DHCP, firewall, routing, WireGuard, or other customer queue was changed.', 'data' => $deployment->fresh()];
    }

    public function apply(Router $router, RouterQosDeployment $deployment, User $user): array
    {
        if ($deployment->router_id !== $router->id || $deployment->status !== 'previewed') {
            return ['success' => false, 'message' => 'Only a current QoS preview for this router can be applied.'];
        }

        // Re-inspect immediately before touching the router, so a configuration
        // change after preview cannot be silently ignored.
        $inspection = $this->mikrotikService->qosInspection($router);
        if (!$inspection['success']) return $inspection;
        $inspection['data'] = $this->withVerifiedProvisioningTopology($router, $inspection['data']);
        $analysis = $this->modeAnalyzer->analyze($inspection['data']);
        if (!$analysis['full']['available']) return ['success' => false, 'message' => 'Full QoS is no longer applicable. No router change was made. ' . implode(' ', $analysis['full']['reasons'])];
        $plan = $this->planner->plan($inspection['data'], $deployment->configuration ?? []);
        if (!$plan['ready']) {
            $deployment->update(['status' => 'refused', 'inspection' => $inspection['data'], 'failure_reason' => implode(' ', $plan['errors'])]);
            return ['success' => false, 'message' => 'QoS deployment was refused after the final safety inspection: ' . implode(' ', $plan['errors']), 'data' => ['preview' => $plan]];
        }

        $deployment->update(['status' => 'applying', 'inspection' => $inspection['data']]);
        $backup = $this->mikrotikService->createQosBackup($router, 'solarnet-qos-v' . $deployment->configuration_version . '-' . now()->format('YmdHis'));
        if (!$backup['success']) {
            $deployment->update(['status' => 'failed', 'failure_reason' => $backup['message']]);
            return $backup;
        }

        $deployment->update(['backup_filename' => $backup['backup_file'], 'backup_verified_at' => now()]);
        $result = $this->mikrotikService->applyManagedQosTrees($router, $deployment->id, $plan['configuration']);
        if (!$result['success']) {
            $deployment->update(['status' => 'failed', 'verification' => $result['verification'] ?? null, 'failure_reason' => $result['message']]);
            return $result;
        }

        $deployment->update([
            'status' => 'active',
            'strategy' => $plan['configuration']['strategy'],
            'queue_type' => $plan['configuration']['queue_type'],
            'configuration' => $plan['configuration'],
            'verification' => $result['verification'] ?? null,
            'applied_by' => $user->id,
            'applied_at' => now(),
            'failure_reason' => null,
        ]);

        return ['success' => true, 'message' => 'QoS was backed up, applied, and verified. Customer Simple Queue limits were not changed.', 'data' => $deployment->fresh()];
    }

    /** Roll back the exact SolarNet-owned resource recorded by this deployment. */
    public function rollback(Router $router, RouterQosDeployment $deployment, User $user): array
    {
        if ($deployment->router_id !== $router->id || !in_array($deployment->status, ['active', 'failed', 'safe_testing', 'safe_test_passed'], true)) {
            return ['success' => false, 'message' => 'This QoS deployment cannot be rolled back from its current state.'];
        }

        if ($this->isSafeDeployment($deployment)) {
            $configuration = $deployment->configuration ?? [];
            $customer = Customer::query()->where('id', $configuration['customer_id'] ?? null)->where('router_id', $router->id)->first();
            if (!$customer || !is_array($configuration['before'] ?? null)) return ['success' => false, 'message' => 'The original Safe QoS queue snapshot is unavailable, so the queue cannot be restored safely.'];
            $result = $this->mikrotikService->restoreManagedCustomerQueue($router, $customer, $configuration['before']);
            if (!$result['success']) return $result;
            $deployment->update([
                'status' => 'rolled_back',
                'rolled_back_by' => $user->id,
                'rolled_back_at' => now(),
                'test_completed_at' => $deployment->test_completed_at ?? now(),
                'verification' => array_merge($deployment->verification ?? [], ['rollback' => $result]),
            ]);
            return ['success' => true, 'message' => 'Safe QoS rollback restored only the original SolarNet customer Simple Queue configuration.', 'data' => $deployment->fresh()];
        }

        $result = $this->mikrotikService->removeManagedQosTrees($router);
        if (!$result['success']) return $result;

        $deployment->update([
            'status' => 'rolled_back',
            'rolled_back_by' => $user->id,
            'rolled_back_at' => now(),
            'verification' => array_merge($deployment->verification ?? [], ['rollback' => $result]),
        ]);
        return ['success' => true, 'message' => 'QoS rollback removed only SolarNet-owned queue trees. The verified RouterOS backup remains on the router.', 'data' => $deployment->fresh()];
    }

    public function disable(Router $router, User $user): array
    {
        $deployment = RouterQosDeployment::query()->where('router_id', $router->id)->where('status', 'active')->latest('applied_at')->first();
        if (!$deployment) return ['success' => false, 'message' => 'No active SolarNet QoS deployment exists on this router.'];

        if ($this->isSafeDeployment($deployment)) {
            $configuration = $deployment->configuration ?? [];
            $customer = Customer::query()->where('id', $configuration['customer_id'] ?? null)->where('router_id', $router->id)->first();
            if (!$customer || !is_array($configuration['before'] ?? null)) return ['success' => false, 'message' => 'The original Safe QoS queue snapshot is unavailable, so the queue cannot be restored safely.'];
            $result = $this->mikrotikService->restoreManagedCustomerQueue($router, $customer, $configuration['before']);
            if (!$result['success']) return $result;
            $deployment->update([
                'status' => 'disabled',
                'rolled_back_by' => $user->id,
                'rolled_back_at' => now(),
                'verification' => array_merge($deployment->verification ?? [], ['disable' => $result]),
            ]);
            return ['success' => true, 'message' => 'Safe QoS was disabled by restoring only the original SolarNet customer Simple Queue.', 'data' => $deployment->fresh()];
        }

        $result = $this->mikrotikService->removeManagedQosTrees($router);
        if (!$result['success']) return $result;
        $deployment->update([
            'status' => 'disabled',
            'rolled_back_by' => $user->id,
            'rolled_back_at' => now(),
            'verification' => array_merge($deployment->verification ?? [], ['disable' => $result]),
        ]);
        return ['success' => true, 'message' => 'Emergency QoS disable removed only SolarNet-owned queue trees.', 'data' => $deployment->fresh()];
    }

    private function queueTargetsCustomer(?string $target, ?string $ipAddress): bool
    {
        if (!$target || !$ipAddress) return false;
        $targets = array_values(array_filter(array_map('trim', explode(',', $target))));
        return count($targets) === 1 && in_array($targets[0], [$ipAddress, $ipAddress . '/32'], true);
    }

    private function queueTypePair(string $queueType): string
    {
        $queueType = trim($queueType);
        if ($queueType === '') return '';
        return str_contains($queueType, '/') ? $queueType : $queueType . '/' . $queueType;
    }

    private function isSafeDeployment(RouterQosDeployment $deployment): bool
    {
        return $deployment->strategy === 'safe_existing_simple_queue_fq_codel';
    }

    private function failSafeTestAndRestore(RouterQosDeployment $deployment, ?Router $router, ?Customer $customer, string $reason, array $observations = []): array
    {
        $configuration = $deployment->configuration ?? [];
        $before = $configuration['before'] ?? null;
        $rollback = (!$router || !$customer || !is_array($before))
            ? ['success' => false, 'message' => 'Rollback cannot be attempted because the original queue snapshot is unavailable.']
            : $this->mikrotikService->restoreManagedCustomerQueue($router, $customer, $before);

        $deployment->update([
            'status' => $rollback['success'] ? 'rolled_back' : 'failed',
            'failure_reason' => $reason . ' Rollback: ' . ($rollback['message'] ?? 'not attempted'),
            'rolled_back_at' => $rollback['success'] ? now() : null,
            'test_completed_at' => now(),
            'verification' => array_merge($deployment->verification ?? [], ['safe_test' => ['passed' => false, 'reason' => $reason, 'observations' => $observations, 'rollback' => $rollback]]),
        ]);
        return ['success' => false, 'message' => $deployment->failure_reason, 'data' => $deployment->fresh()];
    }

    public function metrics(Router $router): array
    {
        $result = $this->mikrotikService->qosMetrics($router);
        if (!$result['success']) return $result;

        $metrics = $result['data'];
        $memoryPercent = $metrics['total_memory'] > 0 ? round((1 - ($metrics['free_memory'] / $metrics['total_memory'])) * 100, 1) : null;
        $previous = Cache::get('router-qos-metrics:' . $router->id);
        $dropDelta = is_array($previous) ? max(0, $metrics['queue_drops'] - (int) ($previous['queue_drops'] ?? $metrics['queue_drops'])) : null;
        Cache::put('router-qos-metrics:' . $router->id, ['queue_drops' => $metrics['queue_drops']], now()->addMinutes(5));
        $warnings = [];
        if ($metrics['cpu_load'] > 80) $warnings[] = 'Router CPU is above 80%.';
        if ($memoryPercent !== null && $memoryPercent > 85) $warnings[] = 'Router memory use is above 85%.';
        if ($dropDelta !== null && $dropDelta > 0) $warnings[] = 'Queue drops increased since the previous actual sample.';

        $metrics['memory_used_percent'] = $memoryPercent;
        $metrics['queue_drop_delta'] = $dropDelta;
        $metrics['warnings'] = $warnings;
        $metrics['freshness'] = 'live';
        return ['success' => true, 'data' => $metrics];
    }
}
