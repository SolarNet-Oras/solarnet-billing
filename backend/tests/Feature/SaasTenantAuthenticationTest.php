<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SaasTenantAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.domains_enabled', true);
    }

    public function test_primary_solarnet_tenant_cannot_sign_in_to_shared_tenant_application(): void
    {
        [$tenant, $user] = $this->membership(subscriptionExempt: true);

        $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/login', [
            'tenant' => $tenant->slug,
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnauthorized();

        $token = JWTAuth::claims(['tenant_id' => $tenant->id])->fromUser($user);
        $this
            ->withToken($token)
            ->getJson('https://app.solarnetportal.com/api/v1/saas/auth/me')
            ->assertForbidden();
    }

    public function test_user_cannot_sign_in_to_a_tenant_without_an_active_membership(): void
    {
        [$tenant, $user, $membership] = $this->membership();
        $membership->update(['status' => 'revoked']);

        $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/login', [
            'tenant' => $tenant->slug,
            'email' => $user->email,
            'password' => 'password',
        ])->assertUnauthorized();
    }

    public function test_expired_trial_is_denied_without_changing_the_tenant(): void
    {
        [$tenant, $user] = $this->membership();
        TenantSubscription::create([
            'tenant_id' => $tenant->id,
            'status' => 'trialing',
            'trial_starts_at' => now()->subDays(31),
            'trial_ends_at' => now()->subDay(),
        ]);

        $this->postJson('https://app.solarnetportal.com/api/v1/saas/auth/login', [
            'tenant' => $tenant->slug,
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(402)->assertJsonPath('reason', 'trial_expired');

        $this->assertFalse($tenant->fresh()->subscription_exempt);
    }

    public function test_tenant_authentication_is_not_accepted_on_platform_admin_domain(): void
    {
        [$tenant, $user] = $this->membership(subscriptionExempt: true);

        $this->postJson('https://admin.solarnetportal.com/api/v1/saas/auth/login', [
            'tenant' => $tenant->slug,
            'email' => $user->email,
            'password' => 'password',
        ])->assertNotFound();
    }

    /** @return array{Tenant, User, TenantMembership} */
    private function membership(bool $subscriptionExempt = false): array
    {
        $tenant = Tenant::create([
            'slug' => $subscriptionExempt ? 'solarnet' : 'branch-a',
            'name' => $subscriptionExempt ? 'SolarNet' : 'Branch A',
            'status' => 'active',
            'is_primary' => $subscriptionExempt,
            'subscription_exempt' => $subscriptionExempt,
        ]);
        $user = User::factory()->create(['is_active' => true]);
        $membership = TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_role' => 'tenant_admin',
            'status' => 'active',
            'is_owner' => true,
        ]);

        return [$tenant, $user, $membership];
    }
}
