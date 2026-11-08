<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasModuleIsolationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_is_read_only_and_covers_every_requested_workspace_module(): void
    {
        DB::table('activity_logs')->insert([
            'id' => (string) Str::uuid(),
            'category' => 'test',
            'action' => 'legacy-unassigned-record',
            'method' => 'GET',
            'path' => '/test',
            'response_status' => 200,
            'duration_ms' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('saas:audit-module-isolation'));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($report['read_only']);
        $this->assertFalse($report['tenant_workspace_ready']);
        $this->assertTrue($report['descendant_isolation_ready']);
        $this->assertTrue($report['operational_api_authorization_ready']);
        $this->assertTrue(collect($report['operational_api_routes'])->every('ready'));
        $this->assertContains('production_activation_requires_separate_approval', $report['workspace_release_blockers']);
        $this->assertSame(1, $report['modules']['logs_reports']['roots'][0]['unassigned_records']);
        $this->assertFalse($report['safety']['records_changed']);
        $this->assertSame('keep_tenant_operational_navigation_staged', $report['action']);
        $this->assertEqualsCanonicalizing([
            'dashboard', 'customers', 'billing', 'remittances',
            'collection_monitoring', 'daily_operations', 'attendance_payroll',
            'leave_requests', 'financial_monitoring', 'service_plans',
            'unregistered_devices', 'tickets', 'operations_map', 'sms_advisory',
            'network_devices', 'logs_reports', 'install_app', 'settings',
        ], array_keys($report['modules']));
    }
}
