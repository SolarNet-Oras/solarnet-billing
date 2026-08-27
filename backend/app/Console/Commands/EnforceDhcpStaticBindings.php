<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Services\DhcpSyncService;
use Illuminate\Console\Command;

class EnforceDhcpStaticBindings extends Command
{
    protected $signature = 'dhcp:enforce-static
                            {--router= : Limit maintenance to one saved router ID}
                            {--limit=2 : Maximum exact customer leases to maintain per router (1-10)}
                            {--dry-run : Show the number eligible without changing RouterOS}';

    protected $description = 'Safely maintain a small batch of exact registered DHCP leases as static RouterOS records';

    public function handle(DhcpSyncService $dhcpSyncService): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 10) {
            $this->error('The batch limit must be between 1 and 10 leases per router.');
            return self::INVALID;
        }

        $router = null;
        if ($routerId = $this->option('router')) {
            $router = Router::query()->find($routerId);
            if (! $router) {
                $this->error("Router not found: {$routerId}");
                return self::FAILURE;
            }
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun
            ? 'Dry run: checking exact registered DHCP leases only; RouterOS will not be changed.'
            : 'Running bounded DHCP static-lease maintenance. Only exact current registered matches are eligible.');

        $result = $dhcpSyncService->enforceRegisteredLeaseStaticBatches($limit, $router, $dryRun);
        foreach ($result['routers'] as $row) {
            $this->newLine();
            $this->line("Router: {$row['router']}");
            $this->line("  Eligible this batch: {$row['eligible']} / {$row['limit']}");
            $this->line("  Attempted: {$row['attempted']}");
            $this->line("  Made static: {$row['made_static']}");
            $this->line("  Ownership comments applied: {$row['comments_applied']}");
            $this->line("  Queues synchronized: {$row['queues_synced']}");
            $this->line("  Skipped after recheck: {$row['skipped']}");
            if ($row['errors'] !== []) {
                $this->error('  Errors: ' . implode(' | ', $row['errors']));
            }
        }

        if ($result['failed'] > 0) {
            $this->warn("Maintenance finished with {$result['failed']} router(s) needing attention.");
            return self::FAILURE;
        }

        $this->info('Bounded DHCP static-lease maintenance completed.');
        return self::SUCCESS;
    }
}
