<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AutomationLog;
use App\Models\CollectionPlan;
use App\Models\Concerns\BelongsToTenant;
use App\Models\DailyCashCount;
use App\Models\DhcpLease;
use App\Models\OltDevice;
use App\Models\OperationsMapAsset;
use App\Models\Router;
use App\Models\Setting;
use App\Models\SmsAdvisoryCampaign;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffLeaveRequest;
use App\Models\StaffPayrollDisbursement;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\WireguardPeer;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SaasOperationalTenantOwnershipTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<class-string, string> */
    private array $roots = [
        CollectionPlan::class => 'collection_plans',
        DailyCashCount::class => 'daily_cash_counts',
        StaffAttendanceRecord::class => 'staff_attendance_records',
        StaffPayrollDisbursement::class => 'staff_payroll_disbursements',
        StaffLeaveRequest::class => 'staff_leave_requests',
        DhcpLease::class => 'dhcp_leases',
        Ticket::class => 'tickets',
        OperationsMapAsset::class => 'operations_map_assets',
        SmsAdvisoryCampaign::class => 'sms_advisory_campaigns',
        Router::class => 'routers',
        OltDevice::class => 'olt_devices',
        WireguardPeer::class => 'wireguard_peers',
        ActivityLog::class => 'activity_logs',
        AutomationLog::class => 'automation_logs',
        Setting::class => 'settings',
    ];

    public function test_every_operational_root_has_nullable_tenant_ownership_and_model_scope(): void
    {
        foreach ($this->roots as $model => $table) {
            $this->assertTrue(Schema::hasColumn($table, 'tenant_id'), $table);
            $this->assertContains(BelongsToTenant::class, class_uses_recursive($model), $model);
        }
    }

    public function test_settings_are_isolated_by_tenant_even_before_global_enforcement(): void
    {
        $solarNet = $this->tenant('solarnet', true);
        $branch = $this->tenant('branch-a');
        $context = app(CurrentTenant::class);

        $context->set($solarNet);
        Setting::put('company.name', 'SolarNet');
        $context->set($branch);
        Setting::put('company.name', 'Branch A');

        $this->assertSame('Branch A', Setting::get('company.name'));
        $context->set($solarNet);
        $this->assertSame('SolarNet', Setting::get('company.name'));
        $this->assertSame(2, Setting::withoutGlobalScope('tenant')->where('key', 'company.name')->count());
    }

    public function test_only_primary_tenant_can_read_legacy_settings_before_backfill(): void
    {
        $solarNet = $this->tenant('solarnet', true);
        $branch = $this->tenant('branch-a');
        DB::table('settings')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => null,
            'key' => 'legacy.setting',
            'value' => 'SolarNet only',
            'cast' => 'string',
            'group' => 'general',
            'is_public' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(CurrentTenant::class)->set($solarNet);
        $this->assertSame('SolarNet only', Setting::get('legacy.setting'));
        app(CurrentTenant::class)->set($branch);
        $this->assertSame('missing', Setting::get('legacy.setting', 'missing'));
    }

    private function tenant(string $slug, bool $primary = false): Tenant
    {
        return Tenant::create([
            'slug' => $slug,
            'name' => $primary ? 'SolarNet' : 'Branch A',
            'status' => 'active',
            'is_primary' => $primary,
            'subscription_exempt' => $primary,
        ]);
    }
}
