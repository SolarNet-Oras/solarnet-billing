<?php

namespace App\Console\Commands;

use App\Services\SaasTenantOnboardingService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class OnboardSaasTenant extends Command
{
    protected $signature = 'saas:onboard-tenant
                            {--slug= : Unique ISP tenant slug}
                            {--name= : ISP company display name}
                            {--owner-email= : Existing active owner account email}
                            {--plan= : Existing active SaaS subscription plan code}
                            {--execute : Create the tenant, owner membership, and trial subscription}
                            {--confirm= : Exact tenant-specific confirmation phrase}';

    protected $description = 'Audit or safely onboard one subscription-bound ISP tenant';

    public function handle(SaasTenantOnboardingService $onboarding): int
    {
        $slug = Str::slug((string) $this->option('slug'));
        $name = trim((string) $this->option('name'));
        $ownerEmail = trim((string) $this->option('owner-email'));
        $planCode = trim((string) $this->option('plan'));
        $execute = (bool) $this->option('execute');
        $confirmation = 'CREATE TENANT '.Str::upper($slug);

        $preview = $onboarding->preview($slug, $name, $ownerEmail, $planCode);
        $this->line(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (! $execute) {
            return self::SUCCESS;
        }
        if (! $preview['ready']) {
            return self::FAILURE;
        }
        if (! hash_equals($confirmation, (string) $this->option('confirm'))) {
            $this->error('Execution stopped. Use --confirm="'.$confirmation.'".');

            return self::FAILURE;
        }

        try {
            $result = $onboarding->execute($slug, $name, $ownerEmail, $planCode);
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Tenant onboarding failed safely: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
