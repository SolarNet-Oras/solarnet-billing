<?php

namespace App\Services;

use App\Models\StaffAttendanceRecord;
use App\Models\StaffCompensation;
use App\Models\StaffPayrollDisbursement;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

class StaffPayrollService
{
    public function __construct(private PhilippinePayrollContributionService $contributions) {}

    public function periodFor(CarbonInterface $payDate): array
    {
        $date = Carbon::instance($payDate)->timezone('Asia/Manila')->startOfDay();
        if ($date->day === 15) {
            return [$date->copy()->subMonthNoOverflow()->day(21), $date->copy()->day(4)];
        }
        $releaseDay = min(30, $date->daysInMonth);
        if ($date->day === $releaseDay) {
            return [$date->copy()->day(5), $date->copy()->day(20)];
        }
        throw new \InvalidArgumentException('Payroll may run only on the 15th or the 30th (last day in a short month).');
    }

    public function process(CarbonInterface $payDate, bool $dryRun = false): array
    {
        abort_unless(Schema::hasTable('staff_payroll_disbursements'), 503, 'Payroll storage is not installed. Run migrations.');
        [$start, $end] = $this->periodFor($payDate);
        $users = User::query()->where('is_active', true)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'super_admin'))
            ->whereHas('compensation')->with('compensation')->orderBy('name')->get();
        $result = ['created'=>0, 'emailed'=>0, 'email_failed'=>0, 'skipped'=>0, 'cutoff_start'=>$start->toDateString(), 'cutoff_end'=>$end->toDateString(), 'pay_date'=>$payDate->toDateString()];

        foreach ($users as $user) {
            $values = $this->calculate($user, $start, $end);
            if ($dryRun) { $result['created']++; continue; }
            $payroll = StaffPayrollDisbursement::firstOrCreate(
                ['user_id'=>$user->id, 'pay_date'=>$payDate->toDateString()],
                [...$values, 'cutoff_start'=>$start, 'cutoff_end'=>$end, 'status'=>'scheduled', 'prepared_at'=>now()]
            );
            if ($payroll->wasRecentlyCreated) $result['created']++; else $result['skipped']++;
            if ($payroll->payslip_emailed_at || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) continue;
            try {
                $this->emailPayslip($payroll, $user);
                $payroll->update(['payslip_emailed_at'=>now(), 'email_error'=>null]);
                $result['emailed']++;
            } catch (\Throwable $exception) {
                $payroll->update(['email_error'=>mb_substr($exception->getMessage(), 0, 2000)]);
                Log::error('Payroll payslip email failed.', ['payroll_id'=>$payroll->id, 'user_id'=>$user->id, 'error'=>$exception->getMessage()]);
                $result['email_failed']++;
            }
        }
        return $result;
    }

    private function calculate(User $user, CarbonInterface $start, CarbonInterface $end): array
    {
        /** @var StaffCompensation $profile */
        $profile = $user->compensation;
        $rows = StaffAttendanceRecord::where('user_id', $user->id)->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->get();
        $daily = (float) ($profile->daily_rate ?: ($profile->monthly_salary / max(1, $profile->work_days_per_month)));
        $present = $rows->whereIn('status', ['present', 'late'])->count();
        $base = $daily * $present;
        $late = ($daily / 480) * $rows->sum('late_minutes');
        $overtime = ($daily / 8) * (float) $profile->overtime_multiplier * ($rows->sum('overtime_minutes') / 60);
        $government = $this->contributions->employeeShares((float) $profile->monthly_salary);
        $allowance = (float) $profile->monthly_allowance / 2;
        $sss = $profile->sss_enabled ? $government['sss'] / 2 : 0;
        $philhealth = $profile->philhealth_enabled ? $government['philhealth'] / 2 : 0;
        $pagibig = $profile->pagibig_enabled ? $government['pagibig'] / 2 : 0;
        $cashAdvance = (float) $profile->cash_advance_deduction / 2;
        $other = (float) $profile->monthly_deduction / 2;
        $gross = $base + $overtime + $allowance;
        $deductions = $late + $sss + $philhealth + $pagibig + $cashAdvance + $other;
        $money = fn ($value) => round(max(0, (float) $value), 2);
        return [
            'base_pay'=>$money($base), 'overtime_pay'=>$money($overtime), 'allowance'=>$money($allowance), 'gross_pay'=>$money($gross),
            'late_deduction'=>$money($late), 'sss_deduction'=>$money($sss), 'philhealth_deduction'=>$money($philhealth),
            'pagibig_deduction'=>$money($pagibig), 'cash_advance_deduction'=>$money($cashAdvance), 'other_deductions'=>$money($other),
            'total_deductions'=>$money($deductions), 'net_pay'=>$money($gross - $deductions), 'present_days'=>$present,
            'worked_minutes'=>$rows->sum('worked_minutes'), 'overtime_minutes'=>$rows->sum('overtime_minutes'),
            'calculation_snapshot'=>['daily_rate'=>$daily, 'monthly_salary'=>$profile->monthly_salary, 'rule_version'=>$government['rule_version'], 'attendance_record_ids'=>$rows->pluck('id')->values()->all()],
        ];
    }

    private function emailPayslip(StaffPayrollDisbursement $payroll, User $user): void
    {
        $peso = fn ($value) => 'PHP '.number_format((float) $value, 2);
        $rows = [
            ['Base attendance pay', $payroll->base_pay], ['Overtime pay', $payroll->overtime_pay], ['Allowance', $payroll->allowance],
            ['Gross pay', $payroll->gross_pay], ['Late deduction', -$payroll->late_deduction], ['SSS', -$payroll->sss_deduction],
            ['PhilHealth', -$payroll->philhealth_deduction], ['Pag-IBIG', -$payroll->pagibig_deduction],
            ['Cash advance', -$payroll->cash_advance_deduction], ['Other deductions', -$payroll->other_deductions],
        ];
        $details = collect($rows)->map(fn ($row) => '<tr><td style="padding:7px;border-bottom:1px solid #e5e7eb">'.e($row[0]).'</td><td style="padding:7px;border-bottom:1px solid #e5e7eb;text-align:right">'.e($peso($row[1])).'</td></tr>')->implode('');
        $html = '<div style="font-family:Arial,sans-serif;max-width:650px;margin:auto;color:#172033"><h2>SolarNet Payroll Payslip</h2><p>Hello '.e($user->name).',</p><p>Your semi-monthly payroll for <strong>'.e($payroll->cutoff_start->format('M j, Y')).' to '.e($payroll->cutoff_end->format('M j, Y')).'</strong> was processed for release on <strong>'.e($payroll->pay_date->format('M j, Y')).'</strong>.</p><table style="width:100%;border-collapse:collapse">'.$details.'<tr><td style="padding:10px;font-weight:bold">Net pay</td><td style="padding:10px;text-align:right;font-weight:bold;color:#047857">'.e($peso($payroll->net_pay)).'</td></tr></table><p style="font-size:12px;color:#64748b">Present days: '.e((string) $payroll->present_days).' · Worked hours: '.e(number_format($payroll->worked_minutes / 60, 2)).' · Payroll ID: '.e($payroll->id).'</p></div>';
        Mail::html($html, fn ($message) => $message->to($user->email, $user->name)->subject('SolarNet payslip - '.$payroll->pay_date->format('F j, Y')));
    }
}
