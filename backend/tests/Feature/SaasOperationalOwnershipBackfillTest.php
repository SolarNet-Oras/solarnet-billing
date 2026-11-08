<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasOperationalOwnershipBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const CONFIRMATION = 'ASSIGN LEGACY OPERATIONAL RECORDS TO SOLARNET';

    public function test_default_mode_is_read_only_and_reports_legacy_roots(): void
    {
        $tenant = $this->solarNet();
        $this->legacySetting('company.name', 'SolarNet');

        $this->assertSame(0, Artisan::call('saas:backfill-solarnet-operational-ownership'));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('audit', $report['mode']);
        $this->assertTrue($report['read_only']);
        $this->assertSame(1, $report['tables']['settings']['unassigned']);
        $this->assertSame(1, $report['unassigned_records']);
        $this->assertTrue($report['safe_for_execution']);
        $this->assertTrue($report['navigation_remains_staged']);

        $this->assertDatabaseHas('settings', ['key' => 'company.name', 'tenant_id' => null]);
        $this->assertDatabaseCount('tenants', 1);
        $this->assertSame($tenant->id, Tenant::firstOrFail()->id);
    }

    public function test_execution_requires_exact_confirmation(): void
    {
        $this->solarNet();
        $this->legacySetting('company.name', 'SolarNet');

        $this->artisan('saas:backfill-solarnet-operational-ownership', [
            '--execute' => true,
            '--confirm' => 'WRONG',
        ])->assertFailed();

        $this->assertDatabaseHas('settings', ['key' => 'company.name', 'tenant_id' => null]);
    }

    public function test_confirmed_backfill_is_idempotent_and_preserves_values(): void
    {
        $tenant = $this->solarNet();
        $this->legacySetting('company.name', 'SolarNet');

        $arguments = ['--execute' => true, '--confirm' => self::CONFIRMATION];
        $this->artisan('saas:backfill-solarnet-operational-ownership', $arguments)
            ->expectsOutputToContain('"invariants_preserved": true')
            ->assertSuccessful();
        $this->artisan('saas:backfill-solarnet-operational-ownership', $arguments)
            ->assertSuccessful();

        $this->assertDatabaseHas('settings', [
            'key' => 'company.name',
            'value' => 'SolarNet',
            'tenant_id' => $tenant->id,
        ]);
        $this->assertDatabaseCount('settings', 1);
    }

    public function test_unassigned_child_linked_to_other_tenant_blocks_execution(): void
    {
        $this->solarNet();
        $other = Tenant::create([
            'slug' => 'other-isp', 'name' => 'Other ISP', 'status' => 'active',
            'is_primary' => false, 'subscription_exempt' => false,
        ]);
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'tenant_id' => $other->id,
            'account_number' => 'OTHER-001',
            'full_name' => 'Other Customer',
            'address' => 'Other address',
            'contact_number' => '09170000000',
            'installation_date' => '2026-01-01',
            'billing_cycle_day' => 1,
            'monthly_fee' => 800,
            'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $routerId = (string) Str::uuid();
        DB::table('routers')->insert([
            'id' => $routerId,
            'tenant_id' => $other->id,
            'name' => 'Other Router',
            'host' => '192.0.2.1',
            'port' => 8728,
            'username' => 'test',
            'password' => 'encrypted-test-value',
            'is_active' => true,
            'connection_status' => 'unknown',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('dhcp_leases')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'router_id' => $routerId,
            'customer_id' => $customerId,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ip_address' => '10.0.0.2',
            'is_dynamic' => false,
            'status' => 'bound',
            'server' => 'dhcp1',
            'last_seen_at' => now(),
            'is_matched' => true,
            'is_current' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('saas:backfill-solarnet-operational-ownership', [
            '--execute' => true,
            '--confirm' => self::CONFIRMATION,
        ])->expectsOutputToContain('"safe_for_execution": false')->assertFailed();

        $this->assertDatabaseHas('dhcp_leases', ['customer_id' => $customerId, 'tenant_id' => null]);
    }

    private function solarNet(): Tenant
    {
        $tenant = Tenant::create([
            'slug' => 'solarnet', 'name' => 'SolarNet', 'status' => 'active',
            'is_primary' => true, 'subscription_exempt' => true,
        ]);
        $user = User::factory()->create();
        DB::table('tenant_user')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_role' => 'tenant_admin',
            'status' => 'active',
            'is_owner' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $tenant;
    }

    private function legacySetting(string $key, string $value): void
    {
        DB::table('settings')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'key' => $key,
            'value' => $value,
            'cast' => 'string',
            'group' => 'general',
            'is_public' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
