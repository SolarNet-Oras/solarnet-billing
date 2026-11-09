<?php

namespace Tests\Feature;

use App\Models\ServicePlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ServicePlanTenantVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.domains_enabled', true);
        config()->set('saas.enforcement_mode', 'enforced');
        config()->set('saas.workspace_activation_mode', 'canary');
        config()->set('saas.workspace_canary_tenants', ['branch-a']);
    }

    public function test_public_catalogue_returns_only_primary_solarnet_plans_without_context(): void
    {
        $solarNet = $this->tenant('solarnet', primary: true);
        $branch = $this->tenant('branch-a');

        app(CurrentTenant::class)->set($solarNet);
        $solarNetPlan = $this->plan('SolarNet 50 Mbps');
        app(CurrentTenant::class)->set($branch);
        $this->plan('Branch Private Plan');
        app(CurrentTenant::class)->clear();

        $this->getJson('https://solarnetportal.com/api/v1/customer-portal/service-plans')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $solarNetPlan->id)
            ->assertJsonMissing(['name' => 'Branch Private Plan']);
    }

    public function test_new_ticket_plan_options_return_only_authenticated_tenant_plans(): void
    {
        $solarNet = $this->tenant('solarnet', primary: true);
        $branch = $this->tenant('branch-a');
        app(CurrentTenant::class)->set($solarNet);
        $this->plan('SolarNet 50 Mbps');
        app(CurrentTenant::class)->set($branch);
        $branchPlan = $this->plan('Branch 80 Mbps');

        $user = User::factory()->create(['is_active' => true]);
        TenantMembership::create([
            'tenant_id' => $branch->id,
            'user_id' => $user->id,
            'membership_role' => 'tenant_staff',
            'status' => 'active',
            'is_owner' => false,
        ]);
        $token = JWTAuth::claims(['tenant_id' => $branch->id])->fromUser($user);

        $this->withToken($token)
            ->getJson('https://app.solarnetportal.com/api/v1/service-plan-options')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $branchPlan->id)
            ->assertJsonMissing(['name' => 'SolarNet 50 Mbps']);
    }

    private function tenant(string $slug, bool $primary = false): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'name' => $primary ? 'SolarNet' : 'Branch A',
            'status' => 'active',
            'is_primary' => $primary,
            'subscription_exempt' => true,
        ]);
    }

    private function plan(string $name): ServicePlan
    {
        return ServicePlan::create([
            'name' => $name,
            'price' => 800,
            'download_speed' => 50,
            'upload_speed' => 50,
            'priority' => 8,
            'is_active' => true,
        ]);
    }
}
