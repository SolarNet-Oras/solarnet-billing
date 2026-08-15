<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Router;
use App\Models\RouterQosDeployment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class RouterQosService
{
    public function __construct(
        private readonly MikrotikService $mikrotikService,
        private readonly RouterQosPlanner $planner,
    ) {
    }

    public function status(Router $router): array
    {
        $inspection = $this->mikrotikService->qosInspection($router);
        if (!$inspection['success']) return $inspection;

        $active = RouterQosDeployment::query()->where('router_id', $router->id)->where('status', 'active')->latest('applied_at')->first();
        return [
            'success' => true,
            'data' => [
                'inspection' => $inspection['data'],
                'active_deployment' => $active,
            ],
        ];
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
                ];
            })->values(),
            'queue_read_warning' => $queueResult['success'] ? null : ($queueResult['message'] ?? 'Router queue read failed.'),
        ];
    }

    public function preview(Router $router, User $user, array $input): array
    {
        $inspection = $this->mikrotikService->qosInspection($router);
        if (!$inspection['success']) return $inspection;

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

    public function apply(Router $router, RouterQosDeployment $deployment, User $user): array
    {
        if ($deployment->router_id !== $router->id || $deployment->status !== 'previewed') {
            return ['success' => false, 'message' => 'Only a current QoS preview for this router can be applied.'];
        }

        // Re-inspect immediately before touching the router, so a configuration
        // change after preview cannot be silently ignored.
        $inspection = $this->mikrotikService->qosInspection($router);
        if (!$inspection['success']) return $inspection;
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

    /** Rollback intentionally removes only the two SolarNet-owned QoS trees. */
    public function rollback(Router $router, RouterQosDeployment $deployment, User $user): array
    {
        if ($deployment->router_id !== $router->id || !in_array($deployment->status, ['active', 'failed'], true)) {
            return ['success' => false, 'message' => 'This QoS deployment cannot be rolled back from its current state.'];
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
