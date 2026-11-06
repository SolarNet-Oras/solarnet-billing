<?php

namespace Tests\Feature;

use App\Models\SaasSubscriptionPlan;
use App\Models\ServicePlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantOnboardingAttempt;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaasTenantOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_onboarding_mode_is_read_only(): void
    {
        [$owner, $plan] = $this->prerequisites();

        $this->artisan('saas:onboard-tenant', [
            '--slug' => 'branch-a',
            '--name' => 'ISP Branch A',
            '--owner-email' => $owner->email,
            '--plan' => $plan->code,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('tenants', ['slug' => 'branch-a']);
        $this->assertDatabaseCount('tenant_onboarding_attempts', 0);
    }

    public function test_execution_requires_the_tenant_specific_confirmation(): void
    {
        [$owner, $plan] = $this->prerequisites();

        $this->artisan('saas:onboard-tenant', [
            '--slug' => 'branch-a',
            '--name' => 'ISP Branch A',
            '--owner-email' => $owner->email,
            '--plan' => $plan->code,
            '--execute' => true,
            '--confirm' => 'CREATE TENANT WRONG',
        ])->assertFailed();

        $this->assertDatabaseMissing('tenants', ['slug' => 'branch-a']);
    }

    public function test_confirmed_onboarding_creates_one_owned_trial_without_billing_records(): void
    {
        [$owner, $plan] = $this->prerequisites();
        // Database timestamps are stored at whole-second precision.
        $before = now()->startOfSecond();

        $this->artisan('saas:onboard-tenant', [
            '--slug' => 'branch-a',
            '--name' => 'ISP Branch A',
            '--owner-email' => $owner->email,
            '--plan' => $plan->code,
            '--execute' => true,
            '--confirm' => 'CREATE TENANT BRANCH-A',
        ])->assertSuccessful();

        $tenant = Tenant::where('slug', 'branch-a')->sole();
        $membership = TenantMembership::where('tenant_id', $tenant->id)->sole();
        $subscription = TenantSubscription::where('tenant_id', $tenant->id)->sole();

        $this->assertFalse($tenant->is_primary);
        $this->assertFalse($tenant->subscription_exempt);
        $this->assertSame($owner->id, $membership->user_id);
        $this->assertTrue($membership->is_owner);
        $this->assertSame('tenant_admin', $membership->membership_role);
        $this->assertSame('trialing', $subscription->status);
        $this->assertSame($plan->id, $subscription->plan_id);
        $this->assertTrue($subscription->trial_starts_at->greaterThanOrEqualTo($before));
        $this->assertEquals(30, $subscription->trial_starts_at->diffInDays($subscription->trial_ends_at));
        $this->assertSame(1, TenantSubscriptionEvent::where('tenant_id', $tenant->id)->count());
        $this->assertSame(1, TenantOnboardingAttempt::where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);

        app(CurrentTenant::class)->set($tenant);
        $servicePlan = ServicePlan::create([
            'name' => 'Branch 50 Mbps',
            'price' => 999,
            'download_speed' => 50,
            'upload_speed' => 50,
            'priority' => 8,
            'is_active' => true,
        ]);
        $this->assertSame($tenant->id, $servicePlan->tenant_id);
    }

    public function test_repeat_or_conflicting_onboarding_is_blocked_without_duplicates(): void
    {
        [$owner, $plan] = $this->prerequisites();
        $arguments = [
            '--slug' => 'branch-a',
            '--name' => 'ISP Branch A',
            '--owner-email' => $owner->email,
            '--plan' => $plan->code,
            '--execute' => true,
            '--confirm' => 'CREATE TENANT BRANCH-A',
        ];

        $this->artisan('saas:onboard-tenant', $arguments)->assertSuccessful();
        $this->artisan('saas:onboard-tenant', $arguments)->assertFailed();

        $this->assertSame(1, Tenant::where('slug', 'branch-a')->count());
        $this->assertDatabaseCount('tenant_subscriptions', 1);
        $this->assertDatabaseCount('tenant_onboarding_attempts', 1);
    }

    /** @return array{User, SaasSubscriptionPlan} */
    private function prerequisites(): array
    {
        $owner = User::factory()->create([
            'email' => 'owner@brancha.example',
            'is_active' => true,
        ]);
        $plan = SaasSubscriptionPlan::create([
            'code' => 'monthly-2999',
            'name' => 'Monthly ISP Subscription',
            'amount' => 2999,
            'currency' => 'PHP',
            'billing_interval' => 'monthly',
            'trial_days' => 30,
            'grace_days' => 0,
            'features' => ['tenant_billing'],
            'is_active' => true,
        ]);

        return [$owner, $plan];
    }
}
