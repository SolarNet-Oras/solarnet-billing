<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\SaasTenantWorkspaceActivationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

class AuditSaasWorkspaceActivation extends Command
{
    protected $signature = 'saas:audit-workspace-activation';
    protected $description = 'Read-only readiness audit for controlled tenant workspace activation';

    public function handle(SaasTenantWorkspaceActivationService $activation): int
    {
        $isolationOutput = new BufferedOutput();
        Artisan::call('saas:audit-module-isolation', [], $isolationOutput);
        $isolation = json_decode($isolationOutput->fetch(), true, flags: JSON_THROW_ON_ERROR);
        $canarySlugs = $activation->canarySlugs();
        $canaries = Tenant::query()->withoutGlobalScopes()
            ->whereIn('slug', $canarySlugs)
            ->get(['id', 'slug', 'name', 'status', 'is_primary'])
            ->map(fn (Tenant $tenant): array => [
                'slug' => $tenant->slug,
                'name' => $tenant->name,
                'status' => $tenant->status,
                'eligible' => $tenant->status === 'active' && ! $tenant->is_primary,
            ])->values();
        $missingCanaries = array_values(array_diff($canarySlugs, $canaries->pluck('slug')->all()));
        $mode = (string) config('saas.workspace_activation_mode', 'staged');
        $foundationReady = (bool) ($isolation['operational_roots_ready'] ?? false)
            && (bool) ($isolation['descendant_isolation_ready'] ?? false)
            && (bool) ($isolation['operational_api_authorization_ready'] ?? false)
            && $missingCanaries === []
            && $canaries->every('eligible');
        $globalEnforcement = (bool) config('saas.enabled')
            && (bool) config('saas.domains_enabled')
            && config('saas.enforcement_mode') === 'enforced';
        $productionAccessEnabled = $foundationReady
            && $globalEnforcement
            && in_array($mode, ['canary', 'active'], true);

        $report = [
            'mode' => 'audit',
            'read_only' => true,
            'foundation_ready' => $foundationReady,
            'production_access_enabled' => $productionAccessEnabled,
            'activation' => [
                'mode' => $mode,
                'configured_canary_slugs' => $canarySlugs,
                'canary_tenants' => $canaries->all(),
                'missing_canary_slugs' => $missingCanaries,
            ],
            'global_enforcement' => [
                'saas_enabled' => (bool) config('saas.enabled'),
                'domains_enabled' => (bool) config('saas.domains_enabled'),
                'enforcement_mode' => (string) config('saas.enforcement_mode'),
                'ready' => $globalEnforcement,
            ],
            'isolation' => [
                'operational_roots_ready' => (bool) ($isolation['operational_roots_ready'] ?? false),
                'descendant_isolation_ready' => (bool) ($isolation['descendant_isolation_ready'] ?? false),
                'operational_api_authorization_ready' => (bool) ($isolation['operational_api_authorization_ready'] ?? false),
            ],
            'runtime_namespaces' => [
                'cache' => 'tenant:{tenant_uuid}:cache:{key}',
                'queue' => 'tenant:{tenant_uuid}:queue:{queue}',
                'storage' => 'tenants/{tenant_uuid}/{path}',
                'fail_closed_without_tenant_context' => true,
            ],
            'rollback' => [
                'set_activation_mode_to_staged' => true,
                'clear_canary_allowlist' => true,
                'disable_global_enforcement' => true,
                'database_rollback_required' => false,
            ],
            'safety' => [
                'records_changed' => false,
                'schema_changed' => false,
                'tenant_navigation_enabled_by_this_command' => false,
            ],
            'action' => $productionAccessEnabled
                ? 'controlled_workspace_access_is_enabled'
                : 'keep_workspace_access_staged',
        ];

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
