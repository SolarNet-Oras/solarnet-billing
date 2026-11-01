<?php

namespace App\Console\Commands;

use App\Services\StaffPayrollService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessScheduledPayroll extends Command
{
    protected $signature = 'payroll:process-scheduled {--date=} {--dry-run}';
    protected $description = 'Prepare and email payslips one day before payday, with an idempotent payday retry; salary release remains wallet-backed';

    public function handle(StaffPayrollService $payroll): int
    {
        if ($this->option('date')) {
            $payDate = Carbon::parse($this->option('date'), 'Asia/Manila')->startOfDay();
            if (! $this->isPayday($payDate)) {
                $this->error('The supplied date is not a valid SolarNet payday.');
                return self::FAILURE;
            }
            return $this->prepare($payroll, $payDate);
        }

        $today = now('Asia/Manila')->startOfDay();

        // Payday is a mandatory retry window. If the previous day's cron run,
        // mail provider, or server was unavailable, prepare missing records and
        // retry unsent payslips today. StaffPayrollService::process is
        // idempotent by employee + pay date, so this never duplicates payroll.
        if ($this->isPayday($today)) {
            $exit = $this->prepare($payroll, $today);
            $this->info('Payday payroll verified. Any missing payslips were prepared and unsent email was retried. Release each salary only after selecting the actual source wallet.');
            return $exit;
        }

        $tomorrow = $today->copy()->addDay();
        if ($this->isPayday($tomorrow)) {
            $exit = $this->prepare($payroll, $tomorrow);
            $this->info('Payslips prepared. Release each salary from Attendance & Payroll after selecting its source wallet.');
            return $exit;
        }

        $this->info('No payslip preparation or payday retry is due today. Salary releases remain available in Attendance & Payroll.');
        return self::SUCCESS;
    }

    private function prepare(StaffPayrollService $payroll, Carbon $payDate): int
    {
        try { $result = $payroll->process($payDate, (bool) $this->option('dry-run')); }
        catch (\InvalidArgumentException $exception) { $this->error($exception->getMessage()); return self::FAILURE; }
        $this->table(['Pay date','Cutoff','Created','Emailed','Email failed','Existing'], [[
            $result['pay_date'], $result['cutoff_start'].' to '.$result['cutoff_end'], $result['created'], $result['emailed'], $result['email_failed'], $result['skipped'],
        ]]);
        return $result['email_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function isPayday(Carbon $date): bool
    {
        return in_array($date->day, [15, min(30, $date->daysInMonth)], true);
    }
}
