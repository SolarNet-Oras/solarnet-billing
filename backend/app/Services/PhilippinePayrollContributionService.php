<?php

namespace App\Services;

final class PhilippinePayrollContributionService
{
    public const RULE_VERSION = 'PH-2025';

    public function employeeShares(float $monthlySalary): array
    {
        $salary = max(0, $monthlySalary);

        // SSS Circular 2024-006, effective January 2025: employee share is 5%
        // of the applicable MSC. Compensation brackets use PHP 500 increments.
        $sssMsc = $salary <= 0 ? 0 : min(35000, max(5000, floor(($salary + 250) / 500) * 500));

        // PhilHealth: 5% total premium, shared equally by employer and employee.
        $philHealthBase = $salary <= 0 ? 0 : min(100000, max(10000, $salary));

        // Pag-IBIG employee share: 1% up to PHP 1,500, otherwise 2%, using the
        // PHP 10,000 maximum monthly fund salary effective February 2024.
        $pagIbigRate = $salary <= 1500 ? .01 : .02;

        return [
            'sss' => round($sssMsc * .05, 2),
            'philhealth' => round($philHealthBase * .025, 2),
            'pagibig' => round(min($salary, 10000) * $pagIbigRate, 2),
            'rule_version' => self::RULE_VERSION,
        ];
    }
}
