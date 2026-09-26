<?php

namespace App\Services;

use App\Models\InstallationIncentiveAllocation;
use App\Models\InstallationIncentivePool;
use App\Models\StaffAttendanceRecord;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class InstallationIncentiveService
{
    public const POOL_AMOUNT = 500.00;

    public function allocate(Ticket $ticket): InstallationIncentivePool
    {
        return DB::transaction(function () use ($ticket): InstallationIncentivePool {
            $existing = InstallationIncentivePool::with('allocations.user')->where('ticket_id', $ticket->id)->lockForUpdate()->first();
            if ($existing) return $existing;
            $installationAt = ($ticket->registered_at ?? now())->copy()->utc();
            $workDate = $installationAt->copy()->timezone('Asia/Manila')->toDateString();
            $eligible = User::query()->where('is_active', true)
                ->whereHas('compensation', fn ($q) => $q->where('employment_status', 'regular'))
                ->where(function ($q) {
                    $q->whereHas('roles', fn ($role) => $role->where('name', 'technician'))
                        ->orWhereHas('compensation', fn ($profile) => $profile->whereRaw("lower(coalesce(job_title, '')) like ?", ['%lineman%'])->orWhereRaw("lower(coalesce(job_title, '')) like ?", ['%technician%']));
                })->get();
            $attendance = StaffAttendanceRecord::whereDate('work_date', $workDate)
                ->whereIn('user_id', $eligible->pluck('id'))->whereIn('status', ['present','late'])->whereNotNull('clocked_in_at')->get()->keyBy('user_id');
            $present = $eligible->filter(function (User $user) use ($attendance, $installationAt): bool {
                $record = $attendance->get($user->id);

                return $record
                    && $this->isOnDutyAt($record->clocked_in_at, $record->clocked_out_at, $installationAt);
            })->values();
            $pool = InstallationIncentivePool::create(['ticket_id'=>$ticket->id,'work_date'=>$workDate,'pool_amount'=>self::POOL_AMOUNT,'eligible_count'=>$present->count(),'status'=>$present->isEmpty()?'unallocated_no_present_staff':'allocated']);
            if ($present->isNotEmpty()) {
                $shares = $this->shares($present->count());
                foreach ($present as $index => $user) {
                    InstallationIncentiveAllocation::create(['pool_id'=>$pool->id,'user_id'=>$user->id,'attendance_record_id'=>$attendance[$user->id]->id,'work_date'=>$workDate,'share_amount'=>$shares[$index],'status'=>'earned']);
                }
            }
            return $pool->fresh('allocations.user');
        });
    }

    public function shares(int $eligibleCount): array
    {
        if ($eligibleCount < 1) return [];
        $base = floor((self::POOL_AMOUNT / $eligibleCount) * 100) / 100;
        $shares = array_fill(0, $eligibleCount, $base);
        $shares[0] = round($shares[0] + (self::POOL_AMOUNT - array_sum($shares)), 2);
        return $shares;
    }

    public function isOnDutyAt(
        CarbonInterface $clockedInAt,
        ?CarbonInterface $clockedOutAt,
        CarbonInterface $installationAt
    ): bool {
        $clockIn = $clockedInAt->copy()->utc();
        $clockOut = $clockedOutAt?->copy()->utc();
        $installed = $installationAt->copy()->utc();

        return $clockIn->lessThanOrEqualTo($installed)
            && ($clockOut === null || $clockOut->greaterThanOrEqualTo($installed));
    }
}
