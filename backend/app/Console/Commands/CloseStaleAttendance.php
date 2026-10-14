<?php

namespace App\Console\Commands;

use App\Models\StaffAttendanceRecord;
use App\Models\StaffPayrollDisbursement;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CloseStaleAttendance extends Command
{
    protected $signature = 'attendance:close-stale {--dry-run}';
    protected $description = 'Close forgotten attendance punches from prior Manila dates at 5:00 PM';

    public function handle(): int
    {
        $today = now('Asia/Manila')->startOfDay();
        $eligible = StaffAttendanceRecord::query()
            ->whereNotNull('clocked_in_at')
            ->whereNull('clocked_out_at')
            ->whereDate('work_date', '<', $today->toDateString())
            ->orderBy('work_date');
        $count = (clone $eligible)->count();
        $closed = 0;
        $payrollLocked = 0;

        if (! $this->option('dry-run')) {
            $eligible->chunkById(100, function ($records) use (&$closed, &$payrollLocked): void {
                foreach ($records as $record) {
                    if (StaffPayrollDisbursement::query()
                        ->where('user_id', $record->user_id)
                        ->whereDate('cutoff_start', '<=', $record->work_date)
                        ->whereDate('cutoff_end', '>=', $record->work_date)
                        ->exists()) {
                        $payrollLocked++;
                        continue;
                    }

                    $record->refresh();
                    if ($record->clocked_out_at !== null) {
                        continue;
                    }

                    $scheduledClose = Carbon::parse($record->work_date->toDateString().' 17:00:00', 'Asia/Manila');
                    $clockIn = $record->clocked_in_at->copy()->timezone('Asia/Manila');
                    // Never manufacture a clock-out before a late clock-in.
                    $clockOut = $clockIn->gt($scheduledClose) ? $clockIn->copy() : $scheduledClose;
                    $worked = (int) floor(max(0, $clockIn->diffInMinutes($clockOut, false)));
                    $note = 'Automatically clocked out at 5:00 PM because no employee clock-out was recorded before midnight.';

                    $record->update([
                        'clocked_out_at' => $clockOut->copy()->utc(),
                        'worked_minutes' => $worked,
                        'overtime_minutes' => 0,
                        'overtime_status' => 'not_applicable',
                        'approved_overtime_minutes' => 0,
                        'overtime_reviewed_by' => null,
                        'overtime_reviewed_at' => null,
                        'overtime_review_notes' => null,
                        'verification_method' => 'automatic_midnight_close',
                        'notes' => trim(($record->notes ? $record->notes."\n" : '').$note),
                    ]);
                    $closed++;
                }
            });
        }

        if ($this->option('dry-run')) {
            $this->info("Eligible stale attendance record(s): {$count}");
        } else {
            $this->info("Closed stale attendance record(s): {$closed}");
            $this->info("Skipped payroll-locked record(s): {$payrollLocked}");
        }
        return self::SUCCESS;
    }
}
