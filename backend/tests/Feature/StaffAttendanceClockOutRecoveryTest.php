<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\StaffAttendanceController;
use App\Models\StaffAttendanceRecord;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class StaffAttendanceClockOutRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('saas.enabled', true);
        config()->set('saas.domains_enabled', true);
        config()->set('saas.enforcement_mode', 'enforced');
    }

    public function test_public_kiosk_resolves_primary_tenant_and_sees_open_punch(): void
    {
        $tenant = $this->solarNet();
        $employee = User::factory()->create(['name' => 'EDMAR CONDE ALCONABA', 'is_active' => true]);
        $this->membership($tenant, $employee);
        $otherTenant = Tenant::create([
            'slug' => 'testing-company',
            'name' => 'Testing Company',
            'status' => 'active',
            'is_primary' => false,
            'subscription_exempt' => false,
        ]);
        $tenantOnlyUser = User::factory()->create(['name' => 'luke mejares', 'is_active' => true]);
        $this->membership($otherTenant, $tenantOnlyUser, owner: true);
        app(CurrentTenant::class)->set($tenant);
        $record = $this->openRecord($employee);
        app(CurrentTenant::class)->clear();

        $this->getJson('https://attendance.solarnetportal.com/api/v1/staff-attendance/kiosk')
            ->assertOk()
            ->assertJsonPath('data.employees.0.id', $employee->id)
            ->assertJsonPath('data.employees.0.record.id', $record->id)
            ->assertJsonPath('data.employees.0.state', 'clocked_in')
            ->assertJsonMissing(['name' => 'luke mejares']);
    }

    public function test_manual_attendance_completes_an_existing_open_punch(): void
    {
        $tenant = $this->solarNet();
        $administrator = User::factory()->create(['name' => 'Administrator', 'is_active' => true]);
        $employee = User::factory()->create(['name' => 'EDMAR CONDE ALCONABA', 'is_active' => true]);
        $this->membership($tenant, $administrator, owner: true);
        $this->membership($tenant, $employee);
        app(CurrentTenant::class)->set($tenant);
        $record = $this->openRecord($employee);
        $date = now('Asia/Manila')->toDateString();

        $request = Request::create('/api/v1/staff-attendance/manual', 'POST', [
            'employee_id' => $employee->id,
            'work_date' => $date,
            'time_in' => '08:00',
            'time_out' => '17:00',
            'reason' => 'Employee could not complete the camera clock-out.',
        ]);
        $request->setUserResolver(fn () => $administrator);

        $response = app(StaffAttendanceController::class)->storeManualAttendance($request);

        $this->assertSame(200, $response->getStatusCode());
        $record->refresh();
        $this->assertNotNull($record->clocked_out_at);
        $this->assertSame(540, $record->worked_minutes);
        $this->assertSame('camera_pin', $record->verification_method);
        $this->assertStringContainsString('Manual clock-out completed by Administrator', (string) $record->notes);
    }

    private function solarNet(): Tenant
    {
        return Tenant::create([
            'slug' => 'solarnet',
            'name' => 'SolarNet',
            'status' => 'active',
            'is_primary' => true,
            'subscription_exempt' => true,
        ]);
    }

    private function openRecord(User $employee): StaffAttendanceRecord
    {
        $clockIn = Carbon::parse(now('Asia/Manila')->toDateString().' 08:00:00', 'Asia/Manila');

        return StaffAttendanceRecord::create([
            'user_id' => $employee->id,
            'work_date' => $clockIn->toDateString(),
            'clocked_in_at' => $clockIn->copy()->utc(),
            'status' => 'present',
            'verification_method' => 'camera_pin',
        ]);
    }

    private function membership(Tenant $tenant, User $user, bool $owner = false): TenantMembership
    {
        return TenantMembership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'membership_role' => $owner ? 'tenant_admin' : 'tenant_staff',
            'status' => 'active',
            'is_owner' => $owner,
        ]);
    }
}
