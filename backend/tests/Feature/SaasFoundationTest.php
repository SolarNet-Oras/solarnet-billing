<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SaasFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_saas_is_inert_by_default(): void
    {
        $this->assertFalse(config('saas.enabled'));
        $this->assertSame('disabled', config('saas.enforcement_mode'));
        $this->assertFalse(config('saas.registration_enabled'));
    }

    public function test_foundation_tables_and_one_subscription_per_tenant_constraint_exist(): void
    {
        foreach ([
            'tenants',
            'tenant_user',
            'saas_subscription_plans',
            'tenant_subscriptions',
            'tenant_subscription_payments',
            'tenant_subscription_events',
            'tenant_support_access_grants',
            'tenant_onboarding_attempts',
            'tenant_usage_snapshots',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing foundation table: {$table}");
        }

        $this->assertTrue(
            collect(Schema::getIndexes('tenant_subscriptions'))
                ->contains(fn (array $index): bool => $index['unique'] && $index['columns'] === ['tenant_id'])
        );
    }

    public function test_bootstrap_defaults_to_a_read_only_audit(): void
    {
        User::factory()->create();

        $this->artisan('saas:bootstrap-solarnet')->assertSuccessful();

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('tenant_user', 0);
    }

    public function test_bootstrap_requires_the_exact_confirmation(): void
    {
        $this->artisan('saas:bootstrap-solarnet', [
            '--execute' => true,
            '--confirm' => 'WRONG',
        ])->assertFailed();

        $this->assertDatabaseCount('tenants', 0);
    }

    public function test_confirmed_bootstrap_is_idempotent_and_preserves_every_user(): void
    {
        User::factory()->count(2)->create();
        $arguments = [
            '--execute' => true,
            '--confirm' => 'CREATE SOLARNET TENANT FOUNDATION',
        ];

        $this->artisan('saas:bootstrap-solarnet', $arguments)->assertSuccessful();
        $this->artisan('saas:bootstrap-solarnet', $arguments)->assertSuccessful();

        $tenant = Tenant::sole();
        $this->assertTrue($tenant->is_primary);
        $this->assertTrue($tenant->subscription_exempt);
        $this->assertSame('solarnet', $tenant->slug);
        $this->assertSame(2, TenantMembership::where('tenant_id', $tenant->id)->count());
        $this->assertDatabaseCount('tenants', 1);
    }
}
