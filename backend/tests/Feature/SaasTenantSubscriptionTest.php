<?php

namespace Tests\Feature;

use App\Models\SaasSubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\FinancialMonitoringService;
use App\Services\SaasSubscriptionPaymentService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function test_owner_can_generate_qrph_and_only_verified_provider_status_activates_subscription(): void
    {
        [$tenant, , $token] = $this->workspace();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/payment_intents')) return Http::response(['data' => ['id' => 'pi_saas_1', 'attributes' => ['client_key' => 'pi_saas_1_client_test']]], 200);
            if (str_ends_with($request->url(), '/payment_methods')) return Http::response(['data' => ['id' => 'pm_saas_1']], 200);
            if (str_ends_with($request->url(), '/payment_intents/pi_saas_1/attach')) return Http::response(['data' => ['attributes' => ['next_action' => ['code' => ['image_url' => 'data:image/png;base64,cXJwaA==']]]]], 200);
            if (str_ends_with($request->url(), '/payment_intents/pi_saas_1')) return Http::response(['data' => ['id' => 'pi_saas_1', 'attributes' => ['status' => 'succeeded']]], 200);
            return Http::response([], 404);
        });

        $response = $this->withToken($token)->postJson('https://app.solarnetportal.com/api/v1/saas/tenant/subscription/qrph')
            ->assertOk()->assertJsonPath('data.qr_image_url', 'data:image/png;base64,cXJwaA==');
        $this->assertStringStartsWith('SAAS-QR-', $response->json('data.reference'));
        $this->assertSame('trialing', $tenant->subscription->fresh()->status);

        $this->assertTrue(app(SaasSubscriptionPaymentService::class)->receivePaymentIntent('pi_saas_1', 'evt_saas_1'));
        $this->assertSame('active', $tenant->subscription->fresh()->status);
        $this->assertDatabaseHas('tenant_subscription_payments', ['tenant_id' => $tenant->id, 'provider_payment_id' => 'pi_saas_1', 'status' => 'paid']);
    }

    public function test_tenant_finance_summary_never_returns_platform_paymongo_wallet(): void
    {
        [$tenant] = $this->workspace();
        app(CurrentTenant::class)->set($tenant);
        Http::preventStrayRequests();

        $summary = app(FinancialMonitoringService::class)->summary('2026-11');

        $this->assertNull($summary['paymongo_account_position']);
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
