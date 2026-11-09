<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\StaffAttendanceController;
use App\Models\StaffAttendanceRecord;
use App\Models\Tenant;
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
        app(CurrentTenant::class)->set($tenant);
        $record = $this->openRecord($employee);
        app(CurrentTenant::class)->clear();

        $this->getJson('https://attendance.solarnetportal.com/api/v1/staff-attendance/kiosk')
            ->assertOk()
            ->assertJsonPath('data.employees.0.id', $employee->id)
            ->assertJsonPath('data.employees.0.record.id', $record->id)
            ->assertJsonPath('data.employees.0.state', 'clocked_in');
    }

    public function test_manual_attendance_completes_an_existing_open_punch(): void
    {
        $tenant = $this->solarNet();
        $administrator = User::factory()->create(['name' => 'Administrator', 'is_active' => true]);
        $employee = User::factory()->create(['name' => 'EDMAR CONDE ALCONABA', 'is_active' => true]);
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
}
