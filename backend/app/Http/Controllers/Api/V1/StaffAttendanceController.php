<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffAttendancePhotoAudit;
use App\Models\StaffCompensation;
use App\Models\StaffLiveLocation;
use App\Models\StaffPayrollDisbursement;
use App\Models\User;
use App\Services\PhilippinePayrollContributionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class StaffAttendanceController extends Controller
{
    public function kiosk(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('staff_attendance_records'), 503, 'Attendance storage is not installed. Run migrations.');
        $now = now('Asia/Manila');
        $users = User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->orderBy('name')
            ->get(['id', 'name', 'attendance_pin_hash']);
        $records = StaffAttendanceRecord::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('work_date', $now->toDateString())
            ->get()
            ->keyBy('user_id');

        return response()->json(['data' => [
            'server_time' => $now->toIso8601String(),
            'timezone' => 'Asia/Manila',
            'employees' => $users->map(function (User $user) use ($records): array {
                $record = $records->get($user->id);
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'pin_configured' => filled($user->attendance_pin_hash),
                    'record' => $record?->only(['id', 'clocked_in_at', 'clocked_out_at', 'status']),
                    'state' => ! $record ? 'not_clocked_in' : ($record->clocked_out_at ? 'clocked_out' : 'clocked_in'),
                ];
            })->values(),
        ]]);
    }

    public function kioskPunch(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('staff_attendance_records'), 503, 'Attendance storage is not installed. Run migrations.');
        $data = $request->validate([
            'employee_id' => ['required', 'uuid', 'exists:users,id'],
            'credential' => ['required', 'string', 'max:255'],
            'credential_type' => ['required', Rule::in(['pin', 'password'])],
            'action' => ['required', Rule::in(['clock_in', 'clock_out'])],
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png', 'max:3072'],
            'photo_consent' => ['accepted'],
            'face_count' => ['required', 'integer', 'in:1'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        $employee = User::query()->whereKey($data['employee_id'])->where('is_active', true)->firstOrFail();
        abort_if($employee->hasRole('super_admin'), 422, 'Super Administrators are not included in attendance or payroll.');
        $validCredential = $data['credential_type'] === 'pin'
            ? filled($employee->attendance_pin_hash) && Hash::check($data['credential'], $employee->attendance_pin_hash)
            : Hash::check($data['credential'], $employee->password);
        abort_unless($validCredential, 422, $data['credential_type'] === 'pin'
            ? 'The PIN does not belong to the selected employee.'
            : 'The employee password is incorrect.');

        if (config('attendance.require_location')) {
            abort_unless(isset($data['latitude'], $data['longitude']), 422, 'Location is required for camera attendance.');
        }

        $cooldown = max(5, (int) config('attendance.scan_cooldown_seconds', 10));
        $cooldownKey = 'attendance-camera:'.$employee->id.':'.$data['action'];
        abort_unless(Cache::add($cooldownKey, true, now()->addSeconds($cooldown)), 429, 'Attendance already recorded. Please wait before trying again.');

        $path = $request->file('photo')->store('attendance/'.now()->format('Y/m'), 'local');
        abort_unless($path, 500, 'The attendance photo could not be stored.');
        $evidence = [
            'photo_path' => $path,
            'photo_captured_at' => now(),
            'ip_address' => $request->ip(),
            'device' => mb_substr((string) $request->userAgent(), 0, 500),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
        ];

        try {
            $response = $data['action'] === 'clock_in'
                ? $this->recordClockIn($employee, $evidence)
                : $this->recordClockOut($employee, $evidence);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($response->getStatusCode() >= 400) Storage::disk('local')->delete($path);

        return $response;
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('staff_attendance_records'), 503, 'Attendance storage is not installed. Run migrations.');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : now('Asia/Manila')->format('Y-m');
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01', 'Asia/Manila')->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $manager = true;
        $canEditPayroll = true;
        $users = User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->with('roles:id,name')
            ->orderBy('name');
        $users = $users->get();
        $records = StaffAttendanceRecord::query()->whereIn('user_id', $users->pluck('id'))->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->orderByDesc('work_date')->get()->groupBy('user_id');
        $todayRecords = StaffAttendanceRecord::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('work_date', now('Asia/Manila')->toDateString())
            ->get()
            ->keyBy('user_id');
        $profiles = StaffCompensation::query()->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        $contributionService = app(PhilippinePayrollContributionService::class);
        $employees = $users->map(function (User $user) use ($records, $todayRecords, $profiles, $manager, $contributionService): array {
            $rows = $records->get($user->id, collect());
            $profile = $profiles->get($user->id);
            $daily = (float) ($profile?->daily_rate ?: (($profile?->monthly_salary ?? 0) / max(1, $profile?->work_days_per_month ?? 26)));
            $presentDays = $rows->whereIn('status', ['present', 'late'])->count();
            $base = $daily * $presentDays;
            $late = ($daily / 480) * $rows->sum('late_minutes');
            $overtime = ($daily / 8) * ((float) ($profile?->overtime_multiplier ?? 1.25)) * ($rows->sum('overtime_minutes') / 60);
            $allowance = (float) ($profile?->monthly_allowance ?? 0);
            $government = $contributionService->employeeShares((float) ($profile?->monthly_salary ?? 0));
            $sss = $profile?->sss_enabled ? $government['sss'] : 0;
            $philhealth = $profile?->philhealth_enabled ? $government['philhealth'] : 0;
            $pagibig = $profile?->pagibig_enabled ? $government['pagibig'] : 0;
            $cashAdvance = (float) ($profile?->cash_advance_deduction ?? 0);
            $otherDeductions = (float) ($profile?->monthly_deduction ?? 0);
            $deductions = $otherDeductions + $sss + $philhealth + $pagibig + $cashAdvance + $late;

            return [
                'id'=>$user->id, 'name'=>$user->name, 'email'=>$user->email, 'phone'=>$user->phone,
                'profile_photo_url'=>$user->profile_photo_url,
                'pin_configured'=>filled($user->attendance_pin_hash),
                'roles'=>$user->roles->pluck('name')->values(),
                'records'=>$rows->values(),
                'today_record'=>$todayRecords->get($user->id),
                'compensation'=>$manager ? $profile : null,
                'summary'=>[
                    'present_days'=>$presentDays, 'absent_days'=>$rows->where('status', 'absent')->count(),
                    'leave_days'=>$rows->where('status', 'leave')->count(),
                    'late_days'=>$rows->where('late_minutes', '>', 0)->count(),
                    'late_minutes'=>$rows->sum('late_minutes'), 'worked_hours'=>round($rows->sum('worked_minutes') / 60, 2),
                    'overtime_hours'=>round($rows->sum('overtime_minutes') / 60, 2),
                    'base_pay'=>round($base, 2), 'overtime_pay'=>round($overtime, 2),
                    'allowance'=>round($allowance, 2), 'late_deduction'=>round($late, 2),
                    'sss_deduction'=>round($sss, 2), 'philhealth_deduction'=>round($philhealth, 2),
                    'pagibig_deduction'=>round($pagibig, 2), 'cash_advance_deduction'=>round($cashAdvance, 2),
                    'other_deductions'=>round($otherDeductions, 2), 'deductions'=>round($deductions, 2),
                    'gross_pay'=>round($base + $overtime + $allowance, 2),
                    'net_pay'=>round(max(0, $base + $overtime + $allowance - $deductions), 2),
                    'government_rule_version'=>$government['rule_version'],
                ],
            ];
        });

        $payrollRuns = Schema::hasTable('staff_payroll_disbursements')
            ? StaffPayrollDisbursement::with('user:id,name,email')
                ->whereBetween('pay_date', [$start->toDateString(), $end->toDateString()])
                ->orderByDesc('pay_date')->orderBy('created_at')->get()
            : collect();

        return response()->json(['data'=>[
            'month'=>$month,
            'can_manage_payroll'=>$canEditPayroll,
            'payroll_policy'=>[
                'first_cutoff'=>'21st of previous month through 4th',
                'first_release'=>'15th at 12:00 PM',
                'second_cutoff'=>'5th through 20th',
                'second_release'=>'30th at 12:00 PM (last day for short months)',
                'timezone'=>'Asia/Manila',
            ],
            'payroll_runs'=>$payrollRuns,
            'employees'=>$employees,
        ]]);
    }

    public function clockIn(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('staff_attendance_records'), 503, 'Attendance storage is not installed. Run migrations.');
        abort_if($request->user()->hasRole('super_admin'), 403, 'Super Administrators are not included in attendance or payroll.');

        return $this->recordClockIn($request->user());
    }

    private function recordClockIn(User $employee, array $evidence = []): JsonResponse
    {
        $now = now('Asia/Manila');
        $profile = StaffCompensation::firstOrCreate(['user_id'=>$employee->id]);
        $scheduled = Carbon::parse($now->toDateString().' '.$profile->scheduled_start, 'Asia/Manila')->addMinutes($profile->grace_minutes);
        $late = max(0, $scheduled->diffInMinutes($now, false));
        $location = Schema::hasTable('staff_live_locations') ? StaffLiveLocation::where('user_id', $employee->id)->first() : null;
        $record = StaffAttendanceRecord::firstOrCreate(
            ['user_id'=>$employee->id, 'work_date'=>$now->toDateString()],
            ['clocked_in_at'=>now(), 'status'=>$late > 0 ? 'late' : 'present', 'late_minutes'=>$late, 'clock_in_latitude'=>$evidence['latitude']??$location?->latitude, 'clock_in_longitude'=>$evidence['longitude']??$location?->longitude, 'verification_method'=>$evidence?'camera_pin':null, 'clock_in_photo_path'=>$evidence['photo_path']??null, 'clock_in_photo_captured_at'=>$evidence['photo_captured_at']??null, 'clock_in_ip_address'=>$evidence['ip_address']??null, 'clock_in_device'=>$evidence['device']??null]
        );
        if (! $record->wasRecentlyCreated) return response()->json(['message'=>'You are already clocked in today.', 'data'=>$record], 409);
        return response()->json(['message'=>'Clock-in recorded.', 'data'=>$record], 201);
    }

    public function clockOut(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('staff_attendance_records'), 503, 'Attendance storage is not installed. Run migrations.');
        abort_if($request->user()->hasRole('super_admin'), 403, 'Super Administrators are not included in attendance or payroll.');

        return $this->recordClockOut($request->user());
    }

    private function recordClockOut(User $employee, array $evidence = []): JsonResponse
    {
        $now = now('Asia/Manila');
        $record = StaffAttendanceRecord::where('user_id', $employee->id)->whereDate('work_date', $now->toDateString())->firstOrFail();
        if ($record->clocked_out_at) return response()->json(['message'=>'You are already clocked out today.', 'data'=>$record], 409);
        $profile = StaffCompensation::firstOrCreate(['user_id'=>$employee->id]);
        $worked = max(0, $record->clocked_in_at->diffInMinutes(now(), false));
        $scheduledEnd = Carbon::parse($now->toDateString().' '.$profile->scheduled_end, 'Asia/Manila');
        $overtime = max(0, $scheduledEnd->diffInMinutes($now, false));
        $location = Schema::hasTable('staff_live_locations') ? StaffLiveLocation::where('user_id', $employee->id)->first() : null;
        $record->update(['clocked_out_at'=>now(), 'worked_minutes'=>$worked, 'overtime_minutes'=>$overtime, 'clock_out_latitude'=>$evidence['latitude']??$location?->latitude, 'clock_out_longitude'=>$evidence['longitude']??$location?->longitude, 'verification_method'=>$evidence?'camera_pin':$record->verification_method, 'clock_out_photo_path'=>$evidence['photo_path']??null, 'clock_out_photo_captured_at'=>$evidence['photo_captured_at']??null, 'clock_out_ip_address'=>$evidence['ip_address']??null, 'clock_out_device'=>$evidence['device']??null]);
        return response()->json(['message'=>'Clock-out recorded.', 'data'=>$record->fresh()]);
    }

    public function updateCompensation(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
        abort_if($user->hasRole('super_admin'), 422, 'Super Administrators are not included in attendance or payroll.');

        $data = $request->validate([
            'employee_name'=>'required|string|max:255', 'employee_email'=>['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'employee_phone'=>'nullable|string|max:30',
            'job_title'=>'nullable|string|max:120', 'employment_status'=>['required', Rule::in(['regular', 'probationary', 'contractual', 'part_time'])],
            'hire_date'=>'nullable|date', 'employee_address'=>'nullable|string|max:1000',
            'monthly_salary'=>'required|numeric|min:0|max:10000000', 'daily_rate'=>'required|numeric|min:0|max:1000000',
            'monthly_allowance'=>'required|numeric|min:0|max:1000000', 'monthly_deduction'=>'required|numeric|min:0|max:1000000',
            'sss_enabled'=>'required|boolean', 'philhealth_enabled'=>'required|boolean',
            'pagibig_enabled'=>'required|boolean', 'cash_advance_deduction'=>'required|numeric|min:0|max:1000000',
            'work_days_per_month'=>'required|integer|min:1|max:31', 'scheduled_start'=>'required|date_format:H:i',
            'scheduled_end'=>'required|date_format:H:i|after:scheduled_start', 'grace_minutes'=>'required|integer|min:0|max:180',
            'overtime_multiplier'=>'required|numeric|min:1|max:5',
        ]);
        $user->update(['name'=>$data['employee_name'], 'email'=>$data['employee_email'], 'phone'=>$data['employee_phone'] ?? null]);
        unset($data['employee_name'], $data['employee_email'], $data['employee_phone']);
        $profile = StaffCompensation::updateOrCreate(['user_id'=>$user->id], [...$data, 'updated_by'=>$request->user()->id]);
        return response()->json(['message'=>'Salary settings updated.', 'data'=>$profile]);
    }

    public function updateAttendancePin(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->hasRole('super_admin'), 403);
        abort_if($user->hasRole('super_admin'), 422, 'Super Administrators are not included in attendance.');

        $data = $request->validate([
            'pin' => ['required', 'digits_between:4,8', 'confirmed'],
            'admin_password' => ['required', 'string', 'max:255'],
        ]);
        abort_unless(Hash::check($data['admin_password'], $request->user()->password), 422, 'Your Super Administrator password is incorrect.');

        $duplicate = User::query()
            ->where('id', '!=', $user->id)
            ->whereNotNull('attendance_pin_hash')
            ->get(['id', 'attendance_pin_hash'])
            ->contains(fn (User $employee): bool => Hash::check($data['pin'], $employee->attendance_pin_hash));
        abort_if($duplicate, 422, 'This attendance PIN is already assigned to another employee. Choose a unique PIN.');

        $user->forceFill(['attendance_pin_hash' => Hash::make($data['pin'])])->save();

        return response()->json(['message' => 'Attendance PIN updated.', 'data' => ['pin_configured' => true]]);
    }

    public function photo(Request $request, StaffAttendanceRecord $attendance, string $type)
    {
        $path = $type === 'clock-in' ? $attendance->clock_in_photo_path : $attendance->clock_out_photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Attendance photo not found.');
        StaffAttendancePhotoAudit::create(['attendance_record_id'=>$attendance->id,'actor_id'=>$request->user()->id,'event'=>'photo_viewed','photo_type'=>$type,'ip_address'=>$request->ip()]);

        return Storage::disk('local')->response($path, null, ['Cache-Control'=>'private, no-store']);
    }
}
