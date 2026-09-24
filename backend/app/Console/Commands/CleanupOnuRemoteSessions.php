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
            $rules = $service->cleanupAllLegacyRouterRules();
            $this->info($sessions.' open legacy public ONU session(s) cleaned; '.$rules.' orphaned MikroTik rule(s) removed.');
            return self::SUCCESS;
        }

        $this->info($service->cleanupExpired().' expired ONU session(s) cleaned.');
        return self::SUCCESS;
    }
}
