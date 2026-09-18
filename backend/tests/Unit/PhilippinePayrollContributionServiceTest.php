<?php

namespace Tests\Unit;

use App\Services\PhilippinePayrollContributionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhilippinePayrollContributionServiceTest extends TestCase
{
    #[DataProvider('salaries')]
    public function test_employee_shares(float $salary, float $sss, float $philhealth, float $pagibig): void
    {
        $result = (new PhilippinePayrollContributionService)->employeeShares($salary);
        $this->assertSame($sss, $result['sss']);
        $this->assertSame($philhealth, $result['philhealth']);
        $this->assertSame($pagibig, $result['pagibig']);
    }

    public static function salaries(): array
    {
        return [
            'no salary' => [0, 0.0, 0.0, 0.0],
            'PHP 8,000' => [8000, 400.0, 250.0, 160.0],
            'PHP 20,000' => [20000, 1000.0, 500.0, 200.0],
            'maximum caps' => [120000, 1750.0, 2500.0, 200.0],
        ];
    }
}
