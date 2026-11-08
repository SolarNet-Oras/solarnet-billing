<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterThreatObservation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use LogicException;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class SaasDescendantAndApiIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.domains_enabled', true);
        config()->set('saas.enforcement_mode', 'enforced');

        Route::middleware(['auth:api', 'tenant.operational'])->get('/_test/tenant-context', function () {
            return response()->json(['tenant_id' => app(CurrentTenant::class)->id()]);
        });
    }

    public function test_descendant_queries_follow_the_owned_parent_tenant(): void
    {
        $first = $this->tenant('branch-a');
        $second = $this->tenant('branch-b');

        app(CurrentTenant::class)->set($first);
        $firstRouter = $this->router('First router');
        $firstObservation = $this->observation($firstRouter);

        app(CurrentTenant::class)->set($second);
        $secondRouter = $this->router('Second router');
        $secondObservation = $this->observation($secondRouter);

        app(CurrentTenant::class)->set($first);
        $this->assertSame([$firstObservation->id], RouterThreatObservation::query()->pluck('id')->all());
        $this->assertNotSame($secondObservation->id, RouterThreatObservation::query()->value('id'));
    }

    public function test_cross_tenant_descendant_mutation_is_blocked(): void
    {
        $first = $this->tenant('branch-a');
        $second = $this->tenant('branch-b');
        app(CurrentTenant::class)->set($second);
        $router = $this->router('Second router');

        app(CurrentTenant::class)->set($first);
        $this->expectException(LogicException::class);
        $this->observation($router);
    }

    public function test_operational_api_resolves_only_the_authenticated_membership_tenant(): void
    {
        $tenant = $this->tenant('branch-a');
        $user = User::factory()->create(['is_active' => true]);
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_role' => 'tenant_admin',
            'status' => 'active',
            'is_owner' => true,
        ]);
        $token = JWTAuth::claims(['tenant_id' => $tenant->id])->fromUser($user);

        $this->withToken($token)
            ->getJson('https://app.solarnetportal.com/_test/tenant-context')
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id);
    }

    public function test_platform_domain_cannot_reach_operational_api(): void
    {
        $tenant = $this->tenant('branch-a');
        $user = User::factory()->create(['is_active' => true]);
        TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_role' => 'tenant_admin',
            'status' => 'active',
            'is_owner' => true,
        ]);
        $token = JWTAuth::claims(['tenant_id' => $tenant->id])->fromUser($user);

        $this->withToken($token)
            ->getJson('https://admin.solarnetportal.com/_test/tenant-context')
            ->assertNotFound();
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

    private function router(string $name): Router
    {
        $router = new Router([
            'name' => $name,
            'host' => fake()->ipv4(),
            'username' => 'api',
            'password' => 'secret',
        ]);
        $router->forceFill(['tenant_id' => app(CurrentTenant::class)->id()]);
        $router->save();

        return $router;
    }

    private function observation(Router $router): RouterThreatObservation
    {
        return RouterThreatObservation::create([
            'router_id' => $router->id,
            'feed_name' => 'test',
            'remote_ip' => fake()->unique()->ipv4(),
            'first_observed_at' => now(),
            'last_observed_at' => now(),
        ]);
    }
}
