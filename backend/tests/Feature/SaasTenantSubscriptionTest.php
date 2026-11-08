<?php

namespace Tests\Feature;

use App\Models\SaasSubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaasTenantSubscriptionTest extends TestCase
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
        config()->set('saas.workspace_canary_read_only', true);
    }

    public function test_owner_sees_tenant_super_administrator_trial_and_plan_summary(): void
    {
        [$tenant, $owner, $token] = $this->workspace();

        $this->withToken($token)->getJson('https://app.solarnetportal.com/api/v1/saas/tenant/subscription')
            ->assertOk()
            ->assertJsonPath('data.tenant.id', $tenant->id)
            ->assertJsonPath('data.administrator.role', 'Tenant Super Administrator')
            ->assertJsonPath('data.administrator.is_owner', true)
            ->assertJsonPath('data.plan.amount', '3499.00')
            ->assertJsonPath('data.trial_days_remaining', 7)
            ->assertJsonPath('data.can_pay', true);

        $this->assertSame('lukeessu.educ@gmail.com', $owner->email);
    }

    public function test_tenant_staff_can_monitor_but_cannot_start_subscription_payment(): void
    {
        [$tenant, , , $password] = $this->workspace();
        $staff = User::factory()->create(['is_active' => true, 'password' => $password]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $staff->id, 'membership_role' => 'tenant_staff', 'status' => 'active', 'is_owner' => false]);
        $token = $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/login', ['tenant' => 'branch-a', 'email' => $staff->email, 'password' => $password])->assertOk()->json('access_token');

        $this->withToken($token)->getJson('https://app.solarnetportal.com/api/v1/saas/tenant/subscription')
            ->assertOk()->assertJsonPath('data.can_pay', false);
        $this->withToken($token)->postJson('https://app.solarnetportal.com/api/v1/saas/tenant/subscription/checkout')->assertForbidden();
    }

    private function workspace(): array
    {
        $plan = SaasSubscriptionPlan::create(['code' => 'monthly-3499', 'name' => 'Monthly ISP Subscription', 'amount' => 3499, 'currency' => 'PHP', 'billing_interval' => 'monthly', 'trial_days' => 7, 'grace_days' => 3, 'is_active' => true]);
        $tenant = Tenant::create(['slug' => 'branch-a', 'name' => 'Testing Company', 'status' => 'active', 'is_primary' => false, 'subscription_exempt' => false]);
        $password = 'password123';
        $owner = User::factory()->create(['name' => 'luke mejares', 'email' => 'lukeessu.educ@gmail.com', 'is_active' => true, 'password' => $password]);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'membership_role' => 'tenant_admin', 'status' => 'active', 'is_owner' => true]);
        TenantSubscription::create(['tenant_id' => $tenant->id, 'plan_id' => $plan->id, 'status' => 'trialing', 'trial_starts_at' => now()->startOfDay(), 'trial_ends_at' => now()->startOfDay()->addDays(7), 'payment_status' => 'not_due']);
        $token = $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/login', ['tenant' => 'branch-a', 'email' => $owner->email, 'password' => $password])->assertOk()->json('access_token');
        return [$tenant, $owner, $token, $password];
    }
}
