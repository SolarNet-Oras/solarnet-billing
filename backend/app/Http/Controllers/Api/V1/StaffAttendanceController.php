<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffCompensation;
use App\Models\StaffLiveLocation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class StaffAttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('staff_attendance_records'), 503, 'Attendance storage is not installed. Run migrations.');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : now('Asia/Manila')->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', 'Asia/Manila')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $manager = $request->user()->hasAnyRole(['super_admin', 'admin', 'accounting']);
        $canEditPayroll = $request->user()->hasAnyRole(['super_admin', 'admin']);
        $users = User::query()->where('is_active', true)->with('roles:id,name')->orderBy('name');
        if (! $manager) $users->whereKey($request->user()->id);
        $users = $users->get();
        $records = StaffAttendanceRecord::query()->whereIn('user_id', $users->pluck('id'))->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->orderByDesc('work_date')->get()->groupBy('user_id');
        $profiles = StaffCompensation::query()->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        $employees = $users->map(function (User $user) use ($records, $profiles, $manager): array {
            $rows = $records->get($user->id, collect());
            $profile = $profiles->get($user->id);
            $daily = (float) ($profile?->daily_rate ?: (($profile?->monthly_salary ?? 0) / max(1, $profile?->work_days_per_month ?? 26)));
            $presentDays = $rows->whereIn('status', ['present', 'late'])->count();
            $base = $daily * $presentDays;
            $late = ($daily / 480) * $rows->sum('late_minutes');
            $overtime = ($daily / 8) * ((float) ($profile?->overtime_multiplier ?? 1.25)) * ($rows->sum('overtime_minutes') / 60);
            $allowance = (float) ($profile?->monthly_allowance ?? 0);
            $deductions = (float) ($profile?->monthly_deduction ?? 0) + $late;

            return [
                'id'=>$user->id, 'name'=>$user->name, 'email'=>$user->email,
                'roles'=>$user->roles->pluck('name')->values(),
                'records'=>$rows->values(),
                'compensation'=>$manager ? $profile : null,
                'summary'=>[
                    'present_days'=>$presentDays, 'late_days'=>$rows->where('late_minutes', '>', 0)->count(),
                    'late_minutes'=>$rows->sum('late_minutes'), 'worked_hours'=>round($rows->sum('worked_minutes') / 60, 2),
                    'overtime_hours'=>round($rows->sum('overtime_minutes') / 60, 2),
                    'base_pay'=>round($base, 2), 'overtime_pay'=>round($overtime, 2),
                    'allowance'=>round($allowance, 2), 'deductions'=>round($deductions, 2),
                    'net_pay'=>round(max(0, $base + $overtime + $allowance - $deductions), 2),
                ],
            ];
        });

        return response()->json(['data'=>['month'=>$month, 'can_manage_payroll'=>$canEditPayroll, 'employees'=>$employees]]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        $now = now('Asia/Manila');
        $profile = StaffCompensation::firstOrCreate(['user_id'=>$request->user()->id]);
        $scheduled = Carbon::parse($now->toDateString().' '.$profile->scheduled_start, 'Asia/Manila')->addMinutes($profile->grace_minutes);
        $late = max(0, $scheduled->diffInMinutes($now, false));
        $location = Schema::hasTable('staff_live_locations') ? StaffLiveLocation::where('user_id', $request->user()->id)->first() : null;
        $record = StaffAttendanceRecord::firstOrCreate(
            ['user_id'=>$request->user()->id, 'work_date'=>$now->toDateString()],
            ['clocked_in_at'=>now(), 'status'=>$late > 0 ? 'late' : 'present', 'late_minutes'=>$late, 'clock_in_latitude'=>$location?->latitude, 'clock_in_longitude'=>$location?->longitude]
        );
        if (! $record->wasRecentlyCreated) return response()->json(['message'=>'You are already clocked in today.', 'data'=>$record], 409);
        return response()->json(['message'=>'Clock-in recorded.', 'data'=>$record], 201);
    }

    public function clockOut(Request $request): JsonResponse
    {
        $now = now('Asia/Manila');
        $record = StaffAttendanceRecord::where('user_id', $request->user()->id)->whereDate('work_date', $now->toDateString())->firstOrFail();
        if ($record->clocked_out_at) return response()->json(['message'=>'You are already clocked out today.', 'data'=>$record], 409);
        $profile = StaffCompensation::firstOrCreate(['user_id'=>$request->user()->id]);
        $worked = max(0, $record->clocked_in_at->diffInMinutes(now(), false));
        $scheduledEnd = Carbon::parse($now->toDateString().' '.$profile->scheduled_end, 'Asia/Manila');
        $overtime = max(0, $scheduledEnd->diffInMinutes($now, false));
        $location = Schema::hasTable('staff_live_locations') ? StaffLiveLocation::where('user_id', $request->user()->id)->first() : null;
        $record->update(['clocked_out_at'=>now(), 'worked_minutes'=>$worked, 'overtime_minutes'=>$overtime, 'clock_out_latitude'=>$location?->latitude, 'clock_out_longitude'=>$location?->longitude]);
        return response()->json(['message'=>'Clock-out recorded.', 'data'=>$record->fresh()]);
    }

    public function updateCompensation(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->hasAnyRole(['super_admin', 'admin']), 403);
        $data = $request->validate([
            'monthly_salary'=>'required|numeric|min:0|max:10000000', 'daily_rate'=>'required|numeric|min:0|max:1000000',
            'monthly_allowance'=>'required|numeric|min:0|max:1000000', 'monthly_deduction'=>'required|numeric|min:0|max:1000000',
            'work_days_per_month'=>'required|integer|min:1|max:31', 'scheduled_start'=>'required|date_format:H:i',
            'scheduled_end'=>'required|date_format:H:i|after:scheduled_start', 'grace_minutes'=>'required|integer|min:0|max:180',
            'overtime_multiplier'=>'required|numeric|min:1|max:5',
        ]);
        $profile = StaffCompensation::updateOrCreate(['user_id'=>$user->id], [...$data, 'updated_by'=>$request->user()->id]);
        return response()->json(['message'=>'Salary settings updated.', 'data'=>$profile]);
    }
}
