<?php

namespace Tests\Feature;

use App\Models\SaasTenantStaffAccessRequest;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\SaasTenantResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SaasTenantStaffAccessTest extends TestCase
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
        config()->set('saas.workspace_canary_read_only', false);
    }

    public function test_staff_request_is_tenant_bound_and_owner_can_approve_without_subscription(): void
    {
        [$tenant,$owner,$token] = $this->owner();

        $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/staff-access-request', [
            'tenant' => 'branch-a', 'name' => 'Branch Staff', 'email' => 'staff@branch.test',
            'phone' => '09170000000', 'password' => 'staffpass123', 'password_confirmation' => 'staffpass123',
            'requested_role' => 'tenant_collector_technician',
        ])->assertCreated();

        $request = SaasTenantStaffAccessRequest::firstOrFail();
        $this->assertSame($tenant->id, $request->tenant_id);

        $this->withToken($token)
            ->postJson("https://app.solarnetportal.com/api/v1/saas/tenant/staff-access-requests/{$request->id}/review", ['decision' => 'approve'])
            ->assertOk();

        $staff = User::where('email', 'staff@branch.test')->firstOrFail();
        $this->assertSame('tenant_collector_technician', $request->requested_role);
        $this->assertDatabaseHas('tenant_user', ['tenant_id' => $tenant->id, 'user_id' => $staff->id, 'membership_role' => 'tenant_collector_technician', 'status' => 'active', 'is_owner' => false]);
        $this->assertDatabaseCount('tenant_subscriptions', 0);
        $this->assertNotSame($owner->id, $staff->id);
    }

    public function test_staff_request_rejects_an_unknown_role(): void
    {
        $this->owner();

        $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/staff-access-request', [
            'tenant' => 'branch-a', 'name' => 'Unsafe Role', 'email' => 'unsafe@branch.test',
            'requested_role' => 'tenant_admin',
            'password' => 'staffpass123', 'password_confirmation' => 'staffpass123',
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('saas_tenant_staff_access_requests', ['email' => 'unsafe@branch.test']);
    }

    public function test_password_recovery_only_notifies_an_active_member_of_the_named_tenant(): void
    {
        Notification::fake();
        [$tenant,$owner] = $this->owner();

        $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/forgot-password', ['tenant' => $tenant->slug, 'email' => $owner->email])->assertOk();
        Notification::assertSentTo($owner, SaasTenantResetPassword::class);

        $outsider = User::factory()->create(['is_active' => true]);
        $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/forgot-password', ['tenant' => $tenant->slug, 'email' => $outsider->email])->assertOk();
        Notification::assertNotSentTo($outsider, SaasTenantResetPassword::class);
    }

    /** @return array{Tenant,User,string} */
    private function owner(): array
    {
        $tenant = Tenant::create(['slug' => 'branch-a', 'name' => 'Branch A', 'status' => 'active', 'is_primary' => false, 'subscription_exempt' => true]);
        $owner = User::factory()->create(['is_active' => true, 'password' => 'password']);
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'membership_role' => 'tenant_admin', 'status' => 'active', 'is_owner' => true]);
        $login = $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/login', ['tenant' => 'branch-a', 'email' => $owner->email, 'password' => 'password'])->assertOk();
        return [$tenant,$owner,$login->json('access_token')];
    }
}
