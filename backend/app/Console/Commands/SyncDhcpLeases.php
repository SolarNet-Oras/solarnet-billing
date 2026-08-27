<?php

namespace App\Console\Commands;

use App\Services\DhcpSyncService;
use Illuminate\Console\Command;

class SyncDhcpLeases extends Command
{
    protected $signature = 'dhcp:sync
                            {--router= : Specific router ID to sync}
                            {--auto-create : Explicitly create customers from unknown bound MAC addresses}
                            {--no-auto-create : Deprecated compatibility option; automatic customer creation is disabled by default}
                            {--read-only : Mirror live DHCP leases locally without RouterOS lease or queue writes}
                            {--enforce-static : Legacy one-shot: also write every exact registered lease to RouterOS}';

    protected $description = 'Sync DHCP leases from MikroTik routers';

    public function handle(DhcpSyncService $dhcpSyncService): int
    {
        $this->info('Starting DHCP lease synchronization...');

        // Unknown network devices must never become customer accounts simply
        // because an unattended scheduled job ran.
        // A full all-at-once static-lease run can make hundreds of serial API
        // calls. Keep the command read-only by default; bounded maintenance is
        // scheduled separately. The legacy write mode remains explicit for a
        // planned maintenance window only.
        $enforceStatic = (bool) $this->option('enforce-static') && ! $this->option('read-only');
        $readOnly = ! $enforceStatic;
        $autoCreate = (bool) $this->option('auto-create')
            && !$this->option('no-auto-create')
            && !$readOnly;
        $routerId = $this->option('router');
        $hasFailures = false;

        if ($readOnly) {
            $this->info('Read-only mode: active lease state will be mirrored locally; RouterOS leases and queues will not be changed.');
        } else {
            $this->warn('Legacy all-at-once static enforcement is enabled. Prefer dhcp:enforce-static for bounded safe batches.');
        }

        if ($routerId) {
            $router = \App\Models\Router::find($routerId);
            if (!$router) {
                $this->error("Router not found: {$routerId}");
                return 1;
            }

            $this->info("Syncing DHCP leases from: {$router->name}");
            $result = $dhcpSyncService->syncRouterLeases($router, $autoCreate, !$readOnly);
            
            $this->displayResult($result);
            $hasFailures = !empty($result['errors']);
        } else {
            $this->info('Syncing DHCP leases from all online routers...');
            $results = $dhcpSyncService->syncAllRouters($autoCreate, !$readOnly);
            
            $this->info("Total routers: {$results['total_routers']}");
            $this->info("Success: {$results['success']}, Failed: {$results['failed']}");
            
            foreach ($results['routers'] as $result) {
                $this->newLine();
                $this->displayResult($result);
            }
            $hasFailures = $results['failed'] > 0;
        }

        $this->newLine();
        if ($hasFailures) {
            $this->warn('DHCP sync completed with issues.');
        } else {
            $this->info('DHCP sync completed!');
        }
        
        return $hasFailures ? self::FAILURE : self::SUCCESS;
    }

    protected function displayResult(array $result): void
    {
        $this->line("Router: {$result['router']}");
        $this->line("  Leases fetched: {$result['leases_fetched']}");
        $this->line("  Leases stored: {$result['leases_stored']}");
        $this->line("  Customers matched: {$result['customers_matched']}");
        $this->line("  Customers created: {$result['customers_created']}");
        $this->line("  IPs updated: {$result['ips_updated']}");
        $this->line("  Queues synced: {$result['queues_synced']}");
        $this->line("  Dynamic registered leases made static: {$result['static_leases_converted']}");
        $this->line("  Exact registered leases verified static: " . ($result['registered_static_leases_verified'] ?? 0));
        $this->line("  SolarNet ownership comments applied: " . ($result['ownership_comments_applied'] ?? 0));
        $this->line("  Static lease checks skipped: {$result['static_lease_skipped']}");
        $this->line("  Queues synchronized after static lease: {$result['queue_syncs_after_static_lease']}");
        $this->line("  Router writes skipped (read-only mode): " . ($result['router_writes_skipped'] ?? 0));
        
        if (!empty($result['errors'])) {
            $this->error("  Errors: " . implode(', ', $result['errors']));
        }
    }
}
