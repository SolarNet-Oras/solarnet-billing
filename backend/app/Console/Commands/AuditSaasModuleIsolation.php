<?php

namespace App\Console\Commands;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditSaasModuleIsolation extends Command
{
    protected $signature = 'saas:audit-module-isolation';
    protected $description = 'Read-only audit of tenant ownership coverage for every ISP workspace module';

    public function handle(): int
    {
        $modules = [];
        $allReady = true;

        foreach (config('saas_module_isolation.modules', []) as $module => $definition) {
            $roots = [];
            foreach ($definition['roots'] as $root) {
                $tableExists = Schema::hasTable($root['table']);
                $tenantColumn = $tableExists && Schema::hasColumn($root['table'], 'tenant_id');
                $modelExists = class_exists($root['model']);
                $modelScoped = $modelExists && in_array(BelongsToTenant::class, class_uses_recursive($root['model']), true);
                $total = $tableExists ? DB::table($root['table'])->count() : null;
                $unassigned = $tenantColumn ? DB::table($root['table'])->whereNull('tenant_id')->count() : null;
                $ready = $tableExists && $tenantColumn && $modelScoped && $unassigned === 0;
                $allReady = $allReady && $ready;
                $roots[] = [
                    'table' => $root['table'],
                    'model' => $root['model'],
                    'table_exists' => $tableExists,
                    'tenant_column' => $tenantColumn,
                    'model_scoped' => $modelScoped,
                    'total_records' => $total,
                    'unassigned_records' => $unassigned,
                    'ready' => $ready,
                ];
            }
            // Dashboard has no storage root and becomes ready only after all
            // operational modules are isolated.
            $moduleReady = $roots !== [] && collect($roots)->every('ready');
            $modules[$module] = ['ready' => $moduleReady, 'roots' => $roots];
            $allReady = $allReady && $moduleReady;
        }

        $rootIsolationReady = collect($modules)
            ->except(['dashboard', 'install_app'])
            ->every(fn (array $module): bool => $module['ready']);
        $releaseBlockers = array_values(config('saas_module_isolation.workspace_release_blockers', []));
        $allReady = $rootIsolationReady && $releaseBlockers === [];
        $modules['dashboard']['ready'] = $allReady;
        $modules['install_app']['ready'] = $allReady;

        $report = [
            'mode' => 'audit',
            'read_only' => true,
            'tenant_workspace_ready' => $allReady,
            'operational_roots_ready' => $rootIsolationReady,
            'workspace_release_blockers' => $releaseBlockers,
            'modules' => $modules,
            'membership_owned_tables' => config('saas_module_isolation.membership_owned'),
            'shared_platform_tables' => config('saas_module_isolation.shared_platform_tables'),
            'safety' => [
                'schema_changed' => false,
                'records_changed' => false,
                'tenant_navigation_should_be_enabled' => $allReady,
            ],
            'action' => $allReady
                ? 'module_isolation_verified'
                : 'keep_tenant_operational_navigation_staged',
        ];

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
