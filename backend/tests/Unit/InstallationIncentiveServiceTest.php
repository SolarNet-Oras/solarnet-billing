<?php

namespace Tests\Unit;

use App\Services\InstallationIncentiveService;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class InstallationIncentiveServiceTest extends TestCase
{
    public function test_pool_is_divided_equally_without_losing_centavos(): void
    {
        $shares = (new InstallationIncentiveService())->shares(3);
        $this->assertSame([166.68, 166.66, 166.66], $shares);
        $this->assertSame(500.0, array_sum($shares));
    }

    public function test_absent_team_has_no_allocations(): void
    {
        $this->assertSame([], (new InstallationIncentiveService())->shares(0));
    }

    public function test_technician_who_clocked_out_before_installation_is_not_on_duty(): void
    {
        $service = new InstallationIncentiveService();

        $this->assertFalse($service->isOnDutyAt(
            CarbonImmutable::parse('2026-09-26 08:00:00', 'Asia/Manila'),
            CarbonImmutable::parse('2026-09-26 12:00:00', 'Asia/Manila'),
            CarbonImmutable::parse('2026-09-26 14:00:00', 'Asia/Manila'),
        ));
    }

    public function test_technician_still_clocked_in_at_installation_receives_a_share(): void
    {
        $service = new InstallationIncentiveService();
        $installation = CarbonImmutable::parse('2026-09-26 14:00:00', 'Asia/Manila');

        $this->assertTrue($service->isOnDutyAt(
            CarbonImmutable::parse('2026-09-26 08:00:00', 'Asia/Manila'),
            null,
            $installation,
        ));
        $this->assertTrue($service->isOnDutyAt(
            CarbonImmutable::parse('2026-09-26 08:00:00', 'Asia/Manila'),
            CarbonImmutable::parse('2026-09-26 17:00:00', 'Asia/Manila'),
            $installation,
        ));
    }
}
