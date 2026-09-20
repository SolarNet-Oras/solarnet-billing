<?php

namespace App\Console\Commands;

use App\Services\StaffPayrollService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessScheduledPayroll extends Command
{
    protected $signature = 'payroll:process-scheduled {--date=} {--dry-run}';
    protected $description = 'Snapshot semi-monthly attendance payroll and email employee payslips';

    public function handle(StaffPayrollService $payroll): int
    {
        $date = $this->option('date') ? Carbon::parse($this->option('date'), 'Asia/Manila') : now('Asia/Manila');
        $releaseDay = min(30, $date->daysInMonth);
        if (! in_array($date->day, [15, $releaseDay], true)) {
            $this->info('No payroll is due today. Scheduled releases are the 15th and the 30th (last day for short months).');
            return self::SUCCESS;
        }
        try { $result = $payroll->process($date, (bool) $this->option('dry-run')); }
        catch (\InvalidArgumentException $exception) { $this->error($exception->getMessage()); return self::FAILURE; }
        $this->table(['Pay date','Cutoff','Created','Emailed','Email failed','Existing'], [[
            $result['pay_date'], $result['cutoff_start'].' to '.$result['cutoff_end'], $result['created'], $result['emailed'], $result['email_failed'], $result['skipped'],
        ]]);
        return $result['email_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
