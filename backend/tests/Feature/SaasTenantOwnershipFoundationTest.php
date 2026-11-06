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
}
