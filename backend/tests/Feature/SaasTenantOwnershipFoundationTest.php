<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasTenantOwnershipFoundationTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tables = [
        'service_plans',
        'customers',
        'invoices',
        'payments',
        'remittances',
        'financial_entries',
    ];

    public function test_core_billing_roots_have_nullable_indexed_tenant_ownership(): void
    {
        foreach ($this->tables as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'tenant_id'), "Missing {$table}.tenant_id");
            $this->assertTrue(
                collect(Schema::getIndexes($table))->contains(
                    fn (array $index): bool => $index['columns'] === ['tenant_id']
                ),
                "Missing {$table}.tenant_id index"
            );
        }
    }

    public function test_ownership_audit_is_read_only_and_reports_unassigned_records(): void
    {
        Tenant::create([
            'slug' => 'solarnet',
            'name' => 'SolarNet',
            'status' => 'active',
            'is_primary' => true,
            'subscription_exempt' => true,
        ]);
        $planId = (string) Str::uuid();
        DB::table('service_plans')->insert([
            'id' => $planId,
            'name' => 'Audit Plan',
            'price' => 800,
            'download_speed' => 50,
            'upload_speed' => 50,
            'priority' => 8,
            'is_active' => true,
            'tenant_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('saas:audit-solarnet-ownership'));
        $output = Artisan::output();
        $this->assertStringContainsString('"read_only": true', $output);
        $this->assertStringContainsString('"service_plans"', $output);
        $this->assertStringContainsString('"unassigned_records":', $output);
        $this->assertStringContainsString('"action": "no_changes_audit_only"', $output);

        $this->assertNull(DB::table('service_plans')->where('id', $planId)->value('tenant_id'));
        $this->assertDatabaseCount('service_plans', 1);
    }

    public function test_backfill_defaults_to_audit_and_requires_exact_confirmation(): void
    {
        [$tenant, $planId] = $this->tenantAndUnassignedPlan();

        $this->assertSame(0, Artisan::call('saas:backfill-solarnet-ownership'));
        $this->assertStringContainsString('"action": "no_changes_audit_only"', Artisan::output());
        $this->assertNull(DB::table('service_plans')->where('id', $planId)->value('tenant_id'));

        $this->assertSame(1, Artisan::call('saas:backfill-solarnet-ownership', [
            '--execute' => true,
            '--confirm' => 'WRONG',
        ]));
        $this->assertNull(DB::table('service_plans')->where('id', $planId)->value('tenant_id'));
        $this->assertTrue($tenant->fresh()->subscription_exempt);
    }

    public function test_confirmed_backfill_is_verified_and_idempotent(): void
    {
        [$tenant, $planId] = $this->tenantAndUnassignedPlan();
        $arguments = [
            '--execute' => true,
            '--confirm' => 'ASSIGN LEGACY BILLING RECORDS TO SOLARNET',
        ];

        $this->assertSame(0, Artisan::call('saas:backfill-solarnet-ownership', $arguments));
        $firstOutput = Artisan::output();
        $this->assertStringContainsString('"financial_invariants_preserved": true', $firstOutput);
        $this->assertStringContainsString('"action": "backfill_completed_and_verified"', $firstOutput);
        $this->assertSame($tenant->id, DB::table('service_plans')->where('id', $planId)->value('tenant_id'));

        $this->assertSame(0, Artisan::call('saas:backfill-solarnet-ownership', $arguments));
        $this->assertStringContainsString('"unassigned_records": 0', Artisan::output());
        $this->assertDatabaseCount('service_plans', 1);
    }

    /** @return array{Tenant, string} */
    private function tenantAndUnassignedPlan(): array
    {
        $tenant = Tenant::create([
            'slug' => 'solarnet',
            'name' => 'SolarNet',
            'status' => 'active',
            'is_primary' => true,
            'subscription_exempt' => true,
        ]);
        $planId = (string) Str::uuid();
        DB::table('service_plans')->insert([
            'id' => $planId,
            'name' => 'Backfill Plan',
            'price' => 800,
            'download_speed' => 50,
            'upload_speed' => 50,
            'priority' => 8,
            'is_active' => true,
            'tenant_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$tenant, $planId];
    }
}
