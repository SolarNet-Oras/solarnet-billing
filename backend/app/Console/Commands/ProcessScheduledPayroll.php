<?php

namespace App\Console\Commands;

use App\Services\StaffPayrollService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\StaffPayrollDisbursement;

class ProcessScheduledPayroll extends Command
{
    protected $signature = 'payroll:process-scheduled {--date=} {--dry-run}';
    protected $description = 'Email payslips one day before payday and release scheduled payroll on payday';

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
        $released = 0;
        if ($this->isPayday($today) && ! $this->option('dry-run')) {
            $released = StaffPayrollDisbursement::whereDate('pay_date', $today->toDateString())
                ->where('status', 'scheduled')
                ->update(['status'=>'released', 'released_at'=>now()]);
        }

        $tomorrow = $today->copy()->addDay();
        if ($this->isPayday($tomorrow)) {
            $exit = $this->prepare($payroll, $tomorrow);
            $this->info("Payroll records released today: {$released}");
            return $exit;
        }

        $this->info("No payslip preparation is due today. Payroll records released today: {$released}");
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
