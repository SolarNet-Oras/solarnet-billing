<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SaasSubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaasPlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.platform_enabled', true);
    }

    public function test_platform_login_requires_primary_super_admin_owner_and_admin_domain(): void
    {
        [$tenant, $admin] = $this->platformAdmin();
        $response = $this->postJson('https://admin.solarnetportal.com/api/v1/saas/platform/auth/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk()->assertJsonPath('platform_tenant.id', $tenant->id);

        $this->withToken($response->json('access_token'))
            ->getJson('https://admin.solarnetportal.com/api/v1/saas/platform/auth/me')
            ->assertOk()->assertJsonPath('user.id', $admin->id);

        $this->postJson('https://billing.solarnetportal.com/api/v1/saas/platform/auth/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertNotFound();
    }

    public function test_ordinary_user_cannot_sign_in_to_platform(): void
    {
        $this->platformAdmin();
        $user = User::factory()->create(['is_active' => true]);
        $this->postJson('https://admin.solarnetportal.com/api/v1/saas/platform/auth/login', [
            'email' => $user->email, 'password' => 'password',
        ])->assertUnauthorized();
    }

    public function test_platform_control_plane_is_independently_staged(): void
    {
        [, $admin] = $this->platformAdmin();
        config()->set('saas.platform_enabled', false);

        $this->postJson('https://admin.solarnetportal.com/api/v1/saas/platform/auth/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertServiceUnavailable();
    }

    public function test_platform_overview_and_preview_first_onboarding_are_tenant_safe(): void
    {
        [, $admin] = $this->platformAdmin();
        $token = $this->platformToken($admin);
        $owner = User::factory()->create(['email' => 'owner@branch.test', 'is_active' => true]);
        SaasSubscriptionPlan::create([
            'code' => 'monthly-3499', 'name' => 'Monthly ISP Subscription', 'amount' => 3499,
            'currency' => 'PHP', 'billing_interval' => 'monthly', 'trial_days' => 7,
            'grace_days' => 3, 'features' => ['tenant_billing'], 'is_active' => true,
        ]);
        $payload = ['slug' => 'branch-a', 'name' => 'Branch A', 'owner_email' => $owner->email, 'plan' => 'monthly-3499'];

        $this->withToken($token)->getJson('https://admin.solarnetportal.com/api/v1/saas/platform/overview')
            ->assertOk()->assertJsonPath('data.summary.tenants', 1);
        $this->withToken($token)->postJson('https://admin.solarnetportal.com/api/v1/saas/platform/tenants/preview', $payload)
            ->assertOk()->assertJsonPath('data.ready', true);
        $this->assertDatabaseMissing('tenants', ['slug' => 'branch-a']);

        $this->withToken($token)->postJson('https://admin.solarnetportal.com/api/v1/saas/platform/tenants', $payload + [
            'confirmation' => 'CREATE TENANT BRANCH-A',
        ])->assertCreated();
        $this->assertDatabaseHas('tenants', ['slug' => 'branch-a', 'is_primary' => false, 'subscription_exempt' => false]);
        $this->assertDatabaseHas('tenant_subscriptions', ['status' => 'trialing']);
    }

    public function test_primary_solarnet_tenant_cannot_be_suspended(): void
    {
        [$tenant, $admin] = $this->platformAdmin();
        $this->withToken($this->platformToken($admin))->patchJson(
            'https://admin.solarnetportal.com/api/v1/saas/platform/tenants/'.$tenant->id.'/status',
            ['status' => 'suspended', 'confirmation' => 'SET TENANT SOLARNET STATUS SUSPENDED']
        )->assertUnprocessable();
        $this->assertSame('active', $tenant->fresh()->status);
    }

    public function test_plan_update_requires_confirmation_and_does_not_rewrite_subscriptions(): void
    {
        [, $admin] = $this->platformAdmin();
        $plan = SaasSubscriptionPlan::create([
            'code' => 'monthly-3499', 'name' => 'Monthly ISP Subscription', 'amount' => 3499,
            'currency' => 'PHP', 'billing_interval' => 'monthly', 'trial_days' => 7,
            'grace_days' => 3, 'features' => ['tenant_billing'], 'is_active' => true,
        ]);
        $payload = [
            'name' => $plan->name, 'amount' => 3799, 'trial_days' => 14,
            'grace_days' => 5, 'is_active' => true,
        ];

        $this->withToken($this->platformToken($admin))->patchJson(
            'https://admin.solarnetportal.com/api/v1/saas/platform/plans/'.$plan->id,
            $payload + ['confirmation' => 'wrong']
        )->assertUnprocessable();
        $this->assertSame('3499.00', $plan->fresh()->amount);

        $this->withToken($this->platformToken($admin))->patchJson(
            'https://admin.solarnetportal.com/api/v1/saas/platform/plans/'.$plan->id,
            $payload + ['confirmation' => 'UPDATE SAAS PLAN MONTHLY-3499']
        )->assertOk();
        $this->assertSame('3799.00', $plan->fresh()->amount);
        $this->assertSame(14, $plan->fresh()->trial_days);
    }

    /** @return array{Tenant, User} */
    private function platformAdmin(): array
    {
        $tenant = Tenant::create(['slug' => 'solarnet', 'name' => 'SolarNet', 'status' => 'active', 'is_primary' => true, 'subscription_exempt' => true]);
        $role = Role::create(['name' => 'super_admin', 'display_name' => 'Super Administrator', 'is_active' => true]);
        $user = User::factory()->create(['email' => 'platform@solarnet.test', 'is_active' => true]);
        $user->roles()->attach($role->id);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'membership_role' => 'tenant_admin', 'status' => 'active', 'is_owner' => true]);

        return [$tenant, $user];
    }

    private function platformToken(User $admin): string
    {
        return $this->postJson('https://admin.solarnetportal.com/api/v1/saas/platform/auth/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk()->json('access_token');
    }
}
