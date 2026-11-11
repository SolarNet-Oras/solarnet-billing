<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\SaasSubscriptionPlan;
use App\Models\SaasTenantApplication;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SaasTenantApplicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.registration_enabled', true);
        config()->set('saas.platform_enabled', true);
        config()->set('saas.tenant_app_enabled', true);
        Mail::fake();
    }

    public function test_public_application_is_app_domain_only_and_creates_no_account_or_financial_record(): void
    {
        $plan = $this->plan();
        $payload = $this->applicationPayload($plan->code);

        $this->postJson('https://billing.solarnetportal.com/api/v1/saas/applications', $payload)->assertNotFound();
        $this->postJson('https://app.solarnetportal.com/api/v1/saas/applications', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_email_verification');

        $this->assertDatabaseHas('saas_tenant_applications', ['owner_email' => 'owner@example.test', 'tenant_slug' => 'sample-isp']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('tenant_subscriptions', 0);
        $this->assertDatabaseCount('tenant_subscription_payments', 0);
    }

    public function test_valid_email_token_moves_application_to_pending_approval_only(): void
    {
        $token = str_repeat('a', 64);
        $application = $this->application($this->plan(), [
            'verification_token_hash' => hash('sha256', $token),
            'verification_expires_at' => now()->addHour(),
        ]);

        $this->postJson('https://app.solarnetportal.com/api/v1/saas/applications/verify', ['token' => $token])
            ->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->assertSame('pending_approval', $application->fresh()->status);
        $this->assertNotNull($application->fresh()->email_verified_at);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_platform_approval_atomically_creates_owner_tenant_and_seven_day_trial(): void
    {
        $plan = $this->plan();
        $application = $this->application($plan, [
            'status' => 'pending_approval',
            'email_verified_at' => now(),
            'submitted_at' => now(),
            'verification_token_hash' => null,
            'verification_expires_at' => null,
        ]);
        $admin = $this->platformAdmin();
        $token = $this->platformToken($admin);
        $confirmation = 'APPROVE ISP APPLICATION '.strtoupper($application->id);

        $this->withToken($token)->postJson(
            'https://admin.solarnetportal.com/api/v1/saas/platform/applications/'.$application->id.'/approve',
            ['confirmation' => $confirmation]
        )->assertOk();

        $application->refresh();
        $this->assertSame('approved', $application->status);
        $this->assertNotNull($application->approved_tenant_id);
        $this->assertDatabaseHas('users', ['email' => $application->owner_email, 'is_active' => true]);
        $this->assertDatabaseHas('tenants', ['slug' => $application->tenant_slug, 'subscription_exempt' => false]);
        $subscription = \App\Models\TenantSubscription::where('tenant_id', $application->approved_tenant_id)->firstOrFail();
        $this->assertSame('trialing', $subscription->status);
        $this->assertEquals(7, $subscription->trial_starts_at->diffInDays($subscription->trial_ends_at));
        $this->assertDatabaseCount('tenant_subscription_payments', 0);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/login', [
            'tenant' => $application->tenant_slug,
            'email' => $application->owner_email,
            'password' => 'SecurePass123',
        ])->assertOk()->assertJsonPath('tenant.id', $application->approved_tenant_id);
    }

    public function test_unverified_application_cannot_be_approved(): void
    {
        $application = $this->application($this->plan());
        $admin = $this->platformAdmin();

        $this->withToken($this->platformToken($admin))->postJson(
            'https://admin.solarnetportal.com/api/v1/saas/platform/applications/'.$application->id.'/approve',
            ['confirmation' => 'APPROVE ISP APPLICATION '.strtoupper($application->id)]
        )->assertUnprocessable();
        $this->assertDatabaseCount('tenants', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_platform_admin_can_delete_an_unapproved_application_with_exact_confirmation(): void
    {
        $application = $this->application($this->plan());
        $admin = $this->platformAdmin();
        $url = 'https://admin.solarnetportal.com/api/v1/saas/platform/applications/'.$application->id;

        $this->withToken($this->platformToken($admin))->deleteJson($url, [
            'confirmation' => 'DELETE ISP APPLICATION WRONG',
        ])->assertUnprocessable();
        $this->assertDatabaseHas('saas_tenant_applications', ['id' => $application->id]);

        $this->withToken($this->platformToken($admin))->deleteJson($url, [
            'confirmation' => 'DELETE ISP APPLICATION '.strtoupper($application->id),
        ])->assertOk()->assertJsonPath('data.approved_tenant_preserved', false);
        $this->assertDatabaseMissing('saas_tenant_applications', ['id' => $application->id]);
    }

    public function test_deleting_an_approved_application_preserves_its_tenant_and_owner(): void
    {
        $plan = $this->plan();
        $admin = $this->platformAdmin();
        $tenant = Tenant::create([
            'slug' => 'approved-isp', 'name' => 'Approved ISP', 'status' => 'active',
            'is_primary' => false, 'subscription_exempt' => false,
        ]);
        $owner = User::factory()->create(['email' => 'approved@example.test']);
        $application = $this->application($plan, [
            'tenant_slug' => $tenant->slug,
            'owner_email' => $owner->email,
            'status' => 'approved',
            'approved_tenant_id' => $tenant->id,
        ]);

        $this->withToken($this->platformToken($admin))->deleteJson(
            'https://admin.solarnetportal.com/api/v1/saas/platform/applications/'.$application->id,
            ['confirmation' => 'DELETE ISP APPLICATION '.strtoupper($application->id)],
        )->assertOk()->assertJsonPath('data.approved_tenant_preserved', true);

        $this->assertDatabaseMissing('saas_tenant_applications', ['id' => $application->id]);
        $this->assertDatabaseHas('tenants', ['id' => $tenant->id, 'slug' => 'approved-isp']);
        $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => 'approved@example.test']);
    }

    private function plan(): SaasSubscriptionPlan
    {
        return SaasSubscriptionPlan::create([
            'code' => 'monthly-3499', 'name' => 'Monthly ISP Subscription', 'amount' => 3499,
            'currency' => 'PHP', 'billing_interval' => 'monthly', 'trial_days' => 7,
            'grace_days' => 3, 'features' => ['tenant_billing'], 'is_active' => true,
        ]);
    }

    private function applicationPayload(string $plan): array
    {
        return [
            'company_name' => 'Sample ISP', 'tenant_slug' => 'sample-isp', 'owner_name' => 'Sample Owner',
            'owner_email' => 'owner@example.test', 'owner_phone' => '09171234567',
            'password' => 'SecurePass123', 'password_confirmation' => 'SecurePass123',
            'business_address' => 'Eastern Samar', 'business_registration_number' => 'DTI-123',
            'plan' => $plan, 'terms_accepted' => true,
        ];
    }

    private function application(SaasSubscriptionPlan $plan, array $overrides = []): SaasTenantApplication
    {
        return SaasTenantApplication::create(array_merge([
            'plan_id' => $plan->id, 'company_name' => 'Sample ISP', 'tenant_slug' => 'sample-isp',
            'owner_name' => 'Sample Owner', 'owner_email' => 'owner@example.test', 'owner_phone' => '09171234567',
            'password_hash' => Hash::make('SecurePass123'), 'business_address' => 'Eastern Samar',
            'status' => 'awaiting_email_verification', 'verification_token_hash' => hash('sha256', str_repeat('b', 64)),
            'verification_expires_at' => now()->addHour(),
        ], $overrides));
    }

    private function platformAdmin(): User
    {
        $tenant = Tenant::create(['slug' => 'solarnet', 'name' => 'SolarNet', 'status' => 'active', 'is_primary' => true, 'subscription_exempt' => true]);
        $role = Role::create(['name' => 'super_admin', 'display_name' => 'Super Administrator', 'is_active' => true]);
        $user = User::factory()->create(['email' => 'platform@solarnet.test', 'is_active' => true]);
        $user->roles()->attach($role->id);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'membership_role' => 'tenant_admin', 'status' => 'active', 'is_owner' => true]);

        return $user;
    }

    private function platformToken(User $admin): string
    {
        return $this->postJson('https://admin.solarnetportal.com/api/v1/saas/platform/auth/login', [
            'email' => $admin->email, 'password' => 'password',
        ])->assertOk()->json('access_token');
    }
}
