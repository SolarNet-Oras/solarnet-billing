<?php

namespace App\Console\Commands;

use App\Services\OnuRemoteAccessService;
use Illuminate\Console\Command;

class CleanupOnuRemoteSessions extends Command
{
    protected $signature='onu-remote:cleanup';
    protected $description='Remove expired SolarNet-owned temporary ONU remote-access rules';

    public function handle(OnuRemoteAccessService $service): int
    {
        $this->info($service->cleanupExpired().' expired ONU session(s) cleaned.');
        return self::SUCCESS;
    }
}
