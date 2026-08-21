<?php

namespace App\Console\Commands;

use App\Services\DhcpSyncService;
use Illuminate\Console\Command;

class SyncDhcpLeases extends Command
{
    protected $signature = 'dhcp:sync
                            {--router= : Specific router ID to sync}
                            {--no-auto-create : Disable auto-creating customers from unknown MACs}';

    protected $description = 'Sync DHCP leases from MikroTik routers';

    public function handle(DhcpSyncService $dhcpSyncService): int
    {
        $this->info('Starting DHCP lease synchronization...');

        $autoCreate = !$this->option('no-auto-create');
        $routerId = $this->option('router');

        if ($routerId) {
            $router = \App\Models\Router::find($routerId);
            if (!$router) {
                $this->error("Router not found: {$routerId}");
                return 1;
            }

            $this->info("Syncing DHCP leases from: {$router->name}");
            $result = $dhcpSyncService->syncRouterLeases($router, $autoCreate);
            
            $this->displayResult($result);
        } else {
            $this->info('Syncing DHCP leases from all online routers...');
            $results = $dhcpSyncService->syncAllRouters($autoCreate);
            
            $this->info("Total routers: {$results['total_routers']}");
            $this->info("Success: {$results['success']}, Failed: {$results['failed']}");
            
            foreach ($results['routers'] as $result) {
                $this->newLine();
                $this->displayResult($result);
            }
        }

        $this->newLine();
        $this->info('DHCP sync completed!');
        
        return 0;
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
        
        if (!empty($result['errors'])) {
            $this->error("  Errors: " . implode(', ', $result['errors']));
        }
    }
}
