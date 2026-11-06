<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaasModuleIsolationAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_is_read_only_and_covers_every_requested_workspace_module(): void
    {
        $this->artisan('saas:audit-module-isolation')
            ->expectsOutputToContain('"read_only": true')
            ->expectsOutputToContain('"tenant_workspace_ready": false')
            ->expectsOutputToContain('"customers"')
            ->expectsOutputToContain('"billing"')
            ->expectsOutputToContain('"remittances"')
            ->expectsOutputToContain('"collection_monitoring"')
            ->expectsOutputToContain('"daily_operations"')
            ->expectsOutputToContain('"attendance_payroll"')
            ->expectsOutputToContain('"leave_requests"')
            ->expectsOutputToContain('"financial_monitoring"')
            ->expectsOutputToContain('"service_plans"')
            ->expectsOutputToContain('"unregistered_devices"')
            ->expectsOutputToContain('"tickets"')
            ->expectsOutputToContain('"operations_map"')
            ->expectsOutputToContain('"sms_advisory"')
            ->expectsOutputToContain('"network_devices"')
            ->expectsOutputToContain('"logs_reports"')
            ->expectsOutputToContain('"install_app"')
            ->expectsOutputToContain('"settings"')
            ->expectsOutputToContain('"records_changed": false')
            ->assertSuccessful();
    }
}
