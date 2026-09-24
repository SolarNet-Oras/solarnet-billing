<?php

namespace App\Console\Commands;

use App\Services\OnuRemoteAccessService;
use Illuminate\Console\Command;

class CleanupOnuRemoteSessions extends Command
{
    protected $signature='onu-remote:cleanup {--all : Close every still-open legacy public ONU session}';
    protected $description='Remove expired SolarNet-owned temporary ONU remote-access rules';

    public function handle(OnuRemoteAccessService $service): int
    {
        if ($this->option('all')) {
            $sessions = $service->cleanupAllOpen();
            $cleanup = $service->cleanupAllLegacyRouterRules();
            $this->info($sessions.' open legacy public ONU session(s) cleaned; '.$cleanup['rules_removed'].' orphaned MikroTik rule(s) removed from '.$cleanup['routers_scanned'].' router(s).');
            if ($cleanup['routers_failed'] > 0) {
                $this->warn($cleanup['routers_failed'].' router(s) could not be inspected. Check the Laravel log for router name and error.');
            }
            return self::SUCCESS;
        }

        $this->info($service->cleanupExpired().' expired ONU session(s) cleaned.');
        return self::SUCCESS;
    }
}
