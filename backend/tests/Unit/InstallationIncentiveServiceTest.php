<?php

namespace Tests\Unit;

use App\Services\InstallationIncentiveService;
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
}
