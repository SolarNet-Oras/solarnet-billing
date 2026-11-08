<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\SaasTenantWorkspaceActivationService;
use App\Support\CurrentTenant;
use App\Support\TenantRuntimeNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use LogicException;
use Tests\TestCase;

class SaasWorkspaceActivationFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_staged_mode_denies_every_shared_tenant_workspace(): void
    {
        $tenant = $this->tenant('branch-a');
        config()->set('saas.workspace_activation_mode', 'staged');

        $decision = app(SaasTenantWorkspaceActivationService::class)->evaluate($tenant);

        $this->assertFalse($decision['allowed']);
        $this->assertSame('workspace_activation_staged', $decision['reason']);
    }

    public function test_canary_mode_allows_only_an_explicit_non_primary_tenant(): void
    {
        $allowed = $this->tenant('branch-a');
        $blocked = $this->tenant('branch-b');
        config()->set('saas.workspace_activation_mode', 'canary');
        config()->set('saas.workspace_canary_tenants', ['branch-a']);

        $service = app(SaasTenantWorkspaceActivationService::class);
        $this->assertTrue($service->evaluate($allowed)['allowed']);
        $this->assertFalse($service->evaluate($blocked)['allowed']);
    }

    public function test_runtime_keys_paths_and_queues_are_tenant_namespaced(): void
    {
        $tenant = $this->tenant('branch-a');
        app(CurrentTenant::class)->set($tenant);
        $namespace = app(TenantRuntimeNamespace::class);

        $this->assertSame("tenant:{$tenant->id}:cache:dashboard", $namespace->cacheKey('dashboard'));
        $this->assertSame("tenant:{$tenant->id}:queue:notifications", $namespace->queue('notifications'));
        $this->assertSame("tenants/{$tenant->id}/logos/company.png", $namespace->storagePath('logos/company.png'));
    }

    public function test_runtime_namespace_fails_closed_without_context(): void
    {
        app(CurrentTenant::class)->clear();
        $this->expectException(LogicException::class);
        app(TenantRuntimeNamespace::class)->cacheKey('unsafe');
    }

    public function test_activation_audit_is_read_only_and_does_not_enable_navigation(): void
    {
        config()->set('saas.workspace_activation_mode', 'staged');
        $this->assertSame(0, Artisan::call('saas:audit-workspace-activation'));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['production_access_enabled']);
        $this->assertFalse($report['safety']['records_changed']);
        $this->assertFalse($report['safety']['tenant_navigation_enabled_by_this_command']);
        $this->assertSame('keep_workspace_access_staged', $report['action']);
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'name' => $slug,
            'status' => 'active',
            'is_primary' => false,
            'subscription_exempt' => true,
        ]);
    }
}
