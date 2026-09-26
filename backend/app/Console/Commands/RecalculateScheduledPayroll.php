<?php

namespace App\Console\Commands;

use App\Models\InstallationIncentiveAllocation;
use App\Models\StaffPayrollDisbursement;
use App\Services\StaffPayrollService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateScheduledPayroll extends Command
{
    private const CONFIRMATION = 'RECALCULATE SCHEDULED PAYROLL';

    protected $signature = 'payroll:recalculate-scheduled {--date= : Scheduled payday in YYYY-MM-DD format} {--confirm= : Exact confirmation phrase}';
    protected $description = 'Safely replace unreleased payroll snapshots for one payday using the current attendance records';

    public function handle(StaffPayrollService $payroll): int
    {
        if (! $this->option('date')) {
            $this->error('The --date option is required.');

            return self::FAILURE;
        }

        try {
            $payDate = Carbon::createFromFormat('Y-m-d', (string) $this->option('date'), 'Asia/Manila')->startOfDay();
            [$cutoffStart, $cutoffEnd] = $payroll->periodFor($payDate);
        } catch (\Throwable $exception) {
            $this->error('Use a valid SolarNet payday in YYYY-MM-DD format: '.$exception->getMessage());

            return self::FAILURE;
        }

        $runs = StaffPayrollDisbursement::query()
            ->with('user:id,name')
            ->whereDate('pay_date', $payDate->toDateString())
            ->orderBy('created_at')
            ->get();

        $this->info('Cutoff: '.$cutoffStart->toDateString().' to '.$cutoffEnd->toDateString());
        $this->table(
            ['Employee', 'Status', 'Gross', 'Deductions', 'Net'],
            $runs->map(fn (StaffPayrollDisbursement $run) => [
                $run->user?->name ?? $run->user_id,
                $run->status,
                number_format($run->gross_pay, 2),
                number_format($run->total_deductions, 2),
                number_format($run->net_pay, 2),
            ])->all()
        );

        if ($runs->isEmpty()) {
            $this->warn('No payroll snapshots exist for this payday. Run payroll:process-scheduled instead.');

            return self::SUCCESS;
        }

        if ($runs->contains(fn (StaffPayrollDisbursement $run) => $run->status !== 'scheduled' || $run->released_at || $run->financial_entry_id)) {
            $this->error('Recalculation stopped. At least one payroll is released or accounting-linked. No record was changed.');

            return self::FAILURE;
        }

        if ($this->option('confirm') !== self::CONFIRMATION) {
            $this->warn('Preview only. No payroll record was changed.');
            $this->line('To execute, add --confirm="'.self::CONFIRMATION.'"');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($runs): void {
            $ids = $runs->pluck('id');

            InstallationIncentiveAllocation::query()
                ->whereIn('payroll_disbursement_id', $ids)
                ->update([
                    'payroll_disbursement_id' => null,
                    'status' => 'earned',
                ]);

            StaffPayrollDisbursement::query()->whereIn('id', $ids)->delete();
        });

        $result = $payroll->process($payDate);

        $this->table(['Pay date', 'Cutoff', 'Created', 'No attendance skipped', 'Emailed', 'Email failed'], [[
            $result['pay_date'],
            $result['cutoff_start'].' to '.$result['cutoff_end'],
            $result['created'],
            $result['no_attendance_skipped'] ?? 0,
            $result['emailed'],
            $result['email_failed'],
        ]]);

        return $result['email_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
