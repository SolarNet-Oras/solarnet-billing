<?php

namespace App\Services;

use App\Models\Router;
use App\Models\RouterDnsBrandingAudit;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Guarded Internal DNS Branding workflow.
 *
 * Every mutation follows discover -> preview -> verified backup -> explicit
 * administrator approval -> apply -> DNS verification. RouterOS objects not
 * tagged with SolarNet-DNS:v1 are never removed or overwritten.
 */
class RouterDnsBrandingService
{
    public function __construct(
        private readonly MikrotikService $mikrotikService,
        private readonly RouterDnsBrandingPlanner $planner,
    ) {
    }

    public function discover(Router $router, User $user): array
    {
        $result = $this->mikrotikService->dnsBrandingDiscovery($router);
        if (!$result['success']) return $result;
        $discovery = $result['data'];
        $discovery['default_domain'] = (string) Setting::get('dns_domain', 'solarnet.local');

        $audit = RouterDnsBrandingAudit::create([
            'router_id' => $router->id,
            'status' => 'discovered_read_only',
            'discovery' => $discovery,
            'discovered_by' => $user->id,
        ]);

        return [
            'success' => true,
            'message' => 'Read-only DNS discovery completed. No RouterOS DNS, DHCP, firewall, NAT, routing, public IP, VLAN, QoS, or billing setting was changed.',
            'data' => ['audit' => $audit, 'discovery' => $discovery],
        ];
    }

    /** Persist a plan only. It never makes a RouterOS API mutation. */
    public function preview(Router $router, RouterDnsBrandingAudit $audit, array $input): array
    {
        if ($audit->router_id !== $router->id || $audit->status !== 'discovered_read_only') {
            return ['success' => false, 'message' => 'Run a new read-only DNS scan before generating a DNS plan.'];
        }

        $planned = $this->planner->build($audit->discovery ?? [], $input);
        if (!$planned['success']) return $planned;

        $audit->update([
            'status' => 'previewed',
            'plan' => $planned['data'],
            'failure_reason' => null,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return [
            'success' => true,
            'message' => 'Internal DNS plan generated. This is a preview only; no RouterOS configuration was changed.',
            'data' => ['audit' => $audit->fresh(), 'plan' => $planned['data']],
        ];
    }

    /** A manual verified backup can be taken before the final approval. */
    public function backup(Router $router, RouterDnsBrandingAudit $audit): array
    {
        if ($audit->router_id !== $router->id || $audit->status !== 'previewed') {
            return ['success' => false, 'message' => 'Preview a current DNS plan before creating its safety backup.'];
        }
        $backup = $this->mikrotikService->createDnsBackup($router, $this->backupName($audit));
        if (!$backup['success']) {
            $audit->update(['status' => 'backup_failed', 'failure_reason' => $backup['message']]);
            return $backup;
        }
        $audit->update(['backup_filename' => $backup['backup_file'], 'failure_reason' => null]);
        return ['success' => true, 'message' => $backup['message'], 'data' => ['audit' => $audit->fresh()]];
    }

    public function apply(Router $router, RouterDnsBrandingAudit $audit, User $user): array
    {
        if ($audit->router_id !== $router->id || $audit->status !== 'previewed' || !is_array($audit->plan)) {
            return ['success' => false, 'message' => 'Only a current DNS preview can be approved and applied.'];
        }

        // Rebuild the plan from a fresh discovery so no record/DHCP value that
        // another administrator changed after preview is ever overwritten.
        $fresh = $this->mikrotikService->dnsBrandingDiscovery($router);
        if (!$fresh['success']) return $fresh;
        $replanned = $this->planner->build($fresh['data'], (array) ($audit->plan['input'] ?? []));
        if (!$replanned['success']) {
            $audit->update(['status' => 'refused_changed_after_preview', 'discovery' => $fresh['data'], 'failure_reason' => $replanned['message']]);
            return ['success' => false, 'message' => 'The router DNS state changed after preview. No RouterOS setting was changed. Run a new scan and review the new plan.', 'data' => ['errors' => $replanned['errors'] ?? []]];
        }

        $plan = $replanned['data'];
        $audit->update([
            'status' => 'applying',
            'discovery' => $fresh['data'],
            'plan' => $plan,
            'approved_by' => $user->id,
            'approved_at' => now(),
            'applied_by' => $user->id,
            'applied_at' => now(),
            'failure_reason' => null,
        ]);

        // Always take a fresh backup immediately before any DNS mutation. A
        // manually-created backup is helpful evidence, but cannot replace this.
        $backup = $this->mikrotikService->createDnsBackup($router, $this->backupName($audit));
        if (!$backup['success']) {
            $audit->update(['status' => 'backup_failed', 'failure_reason' => $backup['message']]);
            return ['success' => false, 'message' => 'DNS apply was blocked because the RouterOS backup could not be verified. ' . $backup['message']];
        }
        $audit->update(['backup_filename' => $backup['backup_file']]);

        $token = $this->auditToken($audit);
        $applied = $this->mikrotikService->applyDnsBranding($router, $plan, $token);
        if (!$applied['success']) return $this->rollbackAfterFailure($router, $audit, $plan, $token, $applied['message']);

        $verification = $this->mikrotikService->verifyDnsBranding($router, $plan, $token);
        if (!$verification['success']) {
            return $this->rollbackAfterFailure($router, $audit, $plan, $token, $verification['message'], $verification['data'] ?? null);
        }

        Setting::put('dns_domain', (string) $plan['domain']);
        $audit->update([
            'status' => 'verified',
            'verification' => $verification['data'],
            'verified_at' => now(),
            'failure_reason' => null,
        ]);

        return [
            'success' => true,
            'message' => 'Internal DNS branding was applied and verified. Internal names and external DNS work; the public IP, WAN, NAT, routing, firewall, VLAN, QoS, and billing configuration were not changed.',
            'data' => ['audit' => $audit->fresh(), 'verification' => $verification['data']],
        ];
    }

    public function rollback(Router $router, RouterDnsBrandingAudit $audit, User $user): array
    {
        if ($audit->router_id !== $router->id || !in_array($audit->status, ['verified', 'applying', 'failed'], true) || !is_array($audit->plan)) {
            return ['success' => false, 'message' => 'This DNS audit cannot be rolled back. Only an applied SolarNet DNS plan is eligible.'];
        }
        $rollback = $this->mikrotikService->rollbackDnsBranding($router, $audit->plan, $this->auditToken($audit));
        $audit->update([
            'status' => $rollback['success'] ? 'rolled_back' : 'rollback_needs_review',
            'verification' => array_merge((array) ($audit->verification ?? []), ['manual_rollback' => $rollback['data'] ?? null, 'rolled_back_by' => $user->id, 'rolled_back_at' => now()->toIso8601String()]),
            'rolled_back_at' => now(),
            'failure_reason' => $rollback['success'] ? null : $rollback['message'],
        ]);
        return array_merge($rollback, ['data' => array_merge((array) ($rollback['data'] ?? []), ['audit' => $audit->fresh()])]);
    }

    /** Tests RouterOS DNS only; no DNS/DHCP/router configuration is changed. */
    public function test(Router $router, RouterDnsBrandingAudit $audit): array
    {
        if ($audit->router_id !== $router->id) return ['success' => false, 'message' => 'DNS audit does not belong to this router.'];
        $hosts = array_map(fn (array $record) => (string) ($record['hostname'] ?? ''), (array) (($audit->plan ?? [])['records'] ?? []));
        if ($hosts === []) {
            $hosts = array_map(fn (array $record) => (string) ($record['name'] ?? ''), array_filter((array) (($audit->discovery ?? [])['static_records'] ?? []), fn (array $record) => $record['owned_by_solarnet'] ?? false));
        }
        return $this->mikrotikService->testDnsBranding($router, $hosts);
    }

    private function rollbackAfterFailure(Router $router, RouterDnsBrandingAudit $audit, array $plan, string $token, string $reason, ?array $verification = null): array
    {
        $rollback = $this->mikrotikService->rollbackDnsBranding($router, $plan, $token);
        $audit->update([
            'status' => $rollback['success'] ? 'rolled_back_after_failed_verification' : 'rollback_needs_review',
            'verification' => array_merge((array) ($verification ?? []), ['automatic_rollback' => $rollback['data'] ?? null]),
            'failure_reason' => $reason . ' Rollback result: ' . $rollback['message'],
            'rolled_back_at' => $rollback['success'] ? now() : null,
        ]);
        return [
            'success' => false,
            'message' => $rollback['success']
                ? $reason . ' SolarNet automatically rolled back only this audit\'s DNS records and DHCP DNS values.'
                : $reason . ' Automatic rollback needs administrator review. Restore the verified RouterOS backup only if the audit record says the owned rollback could not complete.',
            'data' => ['audit' => $audit->fresh(), 'rollback' => $rollback],
        ];
    }

    private function backupName(RouterDnsBrandingAudit $audit): string
    {
        return 'solarnet-dns-' . now()->format('YmdHis') . '-' . $this->auditToken($audit);
    }

    private function auditToken(RouterDnsBrandingAudit $audit): string
    {
        return substr(str_replace('-', '', (string) $audit->id), 0, 12) ?: substr(str_replace('-', '', (string) Str::uuid()), 0, 12);
    }
}
