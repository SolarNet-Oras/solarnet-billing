<?php

namespace Tests\Feature;

use App\Services\StaffPayrollService;
use Carbon\Carbon;
use Mockery;
use Tests\TestCase;

class ProcessScheduledPayrollTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_payday_retries_missing_payroll_and_payslip_email(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 30, 12, 0, 0, 'Asia/Manila'));
        $service = Mockery::mock(StaffPayrollService::class);
        $service->shouldReceive('process')
            ->once()
            ->withArgs(fn ($date, $dryRun) => $date->toDateString() === '2026-10-30' && $dryRun === false)
            ->andReturn([
                'pay_date' => '2026-10-30',
                'cutoff_start' => '2026-10-05',
                'cutoff_end' => '2026-10-20',
                'created' => 3,
                'emailed' => 3,
                'email_failed' => 0,
                'skipped' => 0,
            ]);
        $this->app->instance(StaffPayrollService::class, $service);

        $this->artisan('payroll:process-scheduled')->assertSuccessful();
    }

    public function test_day_before_payday_still_prepares_the_upcoming_cutoff(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 10, 29, 12, 0, 0, 'Asia/Manila'));
        $service = Mockery::mock(StaffPayrollService::class);
        $service->shouldReceive('process')
            ->once()
            ->withArgs(fn ($date, $dryRun) => $date->toDateString() === '2026-10-30' && $dryRun === false)
            ->andReturn([
                'pay_date' => '2026-10-30',
                'cutoff_start' => '2026-10-05',
                'cutoff_end' => '2026-10-20',
                'created' => 3,
                'emailed' => 3,
                'email_failed' => 0,
                'skipped' => 0,
            ]);
        $this->app->instance(StaffPayrollService::class, $service);

        $this->artisan('payroll:process-scheduled')->assertSuccessful();
    }
}
