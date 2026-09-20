<?php

namespace Tests\Unit;

use App\Services\PhilippinePayrollContributionService;
use App\Services\StaffPayrollService;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class StaffPayrollServiceTest extends TestCase
{
    public function test_fifteenth_uses_previous_twenty_first_through_current_fourth(): void
    {
        $service = new StaffPayrollService(new PhilippinePayrollContributionService());
        [$start, $end] = $service->periodFor(Carbon::parse('2026-09-15', 'Asia/Manila'));

        $this->assertSame('2026-08-21', $start->toDateString());
        $this->assertSame('2026-09-04', $end->toDateString());
    }

    public function test_thirtieth_uses_fifth_through_twentieth(): void
    {
        $service = new StaffPayrollService(new PhilippinePayrollContributionService());
        [$start, $end] = $service->periodFor(Carbon::parse('2026-09-30', 'Asia/Manila'));

        $this->assertSame('2026-09-05', $start->toDateString());
        $this->assertSame('2026-09-20', $end->toDateString());
    }

    public function test_last_day_of_february_replaces_the_thirtieth(): void
    {
        $service = new StaffPayrollService(new PhilippinePayrollContributionService());
        [$start, $end] = $service->periodFor(Carbon::parse('2027-02-28', 'Asia/Manila'));

        $this->assertSame('2027-02-05', $start->toDateString());
        $this->assertSame('2027-02-20', $end->toDateString());
    }
}
