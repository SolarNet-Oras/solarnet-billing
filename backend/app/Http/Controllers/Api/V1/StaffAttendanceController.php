<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\StaffAttendanceRecord;
use App\Models\StaffAttendancePhotoAudit;
use App\Models\StaffCompensation;
use App\Models\StaffCashAdvance;
use App\Models\StaffLiveLocation;
use App\Models\StaffPayrollDisbursement;
use App\Models\InstallationIncentivePool;
use App\Models\FinancialEntry;
use App\Models\User;
use App\Services\PhilippinePayrollContributionService;
use App\Services\StaffProfilePhotoService;
use App\Services\CashDenominationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
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
                    'reference_configured' => filled($user->attendance_reference_photo_path),
                    'record' => $record ? [
                        'id' => $record->id,
                        'clocked_in_at' => $record->clocked_in_at?->copy()->utc()->toIso8601String(),
                        'clocked_out_at' => $record->clocked_out_at?->copy()->utc()->toIso8601String(),
                        'clocked_in_at_manila' => $record->clocked_in_at?->copy()->timezone('Asia/Manila')->format('h:i:s A'),
                        'clocked_out_at_manila' => $record->clocked_out_at?->copy()->timezone('Asia/Manila')->format('h:i:s A'),
                        'status' => $record->status,
                    ] : null,
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
            'face_detection_supported' => ['nullable', 'boolean'],
            'face_count' => ['nullable', 'integer', 'in:1'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);
        // Older installed kiosks always submit face_count=1 but do not send
        // the capability flag. Keep them compatible while allowing browsers
        // without the optional FaceDetector API to submit photo evidence for
        // authorized human review.
        $faceDetectionSupported = ! array_key_exists('face_detection_supported', $data)
            || (bool) $data['face_detection_supported'];
        abort_if($faceDetectionSupported && (int) ($data['face_count'] ?? 0) !== 1, 422, 'Exactly one face must be visible.');

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
            'photo_captured_at' => now('UTC'),
            'ip_address' => $request->ip(),
            'device' => mb_substr((string) $request->userAgent(), 0, 500),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'face_detection_supported' => $faceDetectionSupported,
            'verification_method' => $faceDetectionSupported ? 'camera_pin' : 'camera_pin_manual_review',
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
        if ($response->getStatusCode() < 400 && $data['action'] === 'clock_in' && blank($employee->attendance_reference_photo_path)) {
            $referencePath = 'attendance-reference/'.$employee->id.'.jpg';
            if (Storage::disk('local')->copy($path, $referencePath)) {
                $employee->forceFill([
                    'attendance_reference_photo_path' => $referencePath,
                    'attendance_reference_captured_at' => now(),
                ])->save();
            }
        }

        return $response;
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(Schema::hasTable('staff_attendance_records'), 503, 'Attendance storage is not installed. Run migrations.');
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->input('month')) ? $request->input('month') : now('Asia/Manila')->format('Y-m');
        $monthStart = Carbon::createFromFormat('Y-m-d', $month.'-01', 'Asia/Manila')->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $cutoff = in_array($request->input('cutoff'), ['first', 'second'], true) ? $request->input('cutoff') : 'second';
        [$start, $end] = $cutoff === 'first'
            ? [$monthStart->copy()->subMonthNoOverflow()->day(21), $monthStart->copy()->day(4)]
            : [$monthStart->copy()->day(5), $monthStart->copy()->day(20)];
        $manager = true;
        $canEditPayroll = true;
        $users = User::query()
            ->where('is_active', true)
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'super_admin'))
            ->with('roles:id,name')
            ->orderBy('name');
        $users = $users->get();
        $records = StaffAttendanceRecord::query()->with('overtimeReviewer:id,name')->whereIn('user_id', $users->pluck('id'))->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])->orderByDesc('work_date')->get()->groupBy('user_id');
        $todayRecords = StaffAttendanceRecord::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('work_date', now('Asia/Manila')->toDateString())
            ->get()
            ->keyBy('user_id');
        $profiles = StaffCompensation::query()->whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        $contributionService = app(PhilippinePayrollContributionService::class);
        $employees = $users->map(function (User $user) use ($records, $todayRecords, $profiles, $manager, $contributionService, $cutoff): array {
            $rows = $records->get($user->id, collect());
            $serializedRows = $rows->map(fn (StaffAttendanceRecord $row): array => $this->attendanceRecordPayload($row))->values();
            $profile = $profiles->get($user->id);
            $daily = (float) ($profile?->daily_rate ?: (($profile?->monthly_salary ?? 0) / max(1, $profile?->work_days_per_month ?? 26)));
            $payableRows = $rows->whereIn('status', ['present', 'late']);
            $presentDays = $payableRows->count();
            $fullDays = $payableRows->filter(fn (StaffAttendanceRecord $row) => (int) $row->worked_minutes >= 480)->count();
            $halfDays = $payableRows->filter(fn (StaffAttendanceRecord $row) => (int) $row->worked_minutes > 0 && (int) $row->worked_minutes < 480)->count();
            $base = ($daily * $fullDays) + (($daily / 2) * $halfDays);
            $late = ($daily / 480) * $rows->sum('late_minutes');
            $approvedOvertimeMinutes = (int) $rows->sum('approved_overtime_minutes');
            $overtime = ($daily / 8) * ((float) ($profile?->overtime_multiplier ?? 1.25)) * ($approvedOvertimeMinutes / 60);
            $allowance = (float) ($profile?->monthly_allowance ?? 0) / 2;
            $government = $contributionService->employeeShares((float) ($profile?->monthly_salary ?? 0));
            $sss = $profile?->sss_enabled ? $government['sss'] / 2 : 0;
            $philhealth = $profile?->philhealth_enabled ? $government['philhealth'] / 2 : 0;
            $pagibig = $profile?->pagibig_enabled ? $government['pagibig'] / 2 : 0;
            $otherDeductions = (float) ($profile?->monthly_deduction ?? 0) / 2;
            $gross = $base + $overtime + $allowance;
            $nonAdvanceDeductions = $otherDeductions + $sss + $philhealth + $pagibig + $late;
            $outstandingAdvance = (float) StaffCashAdvance::query()->where('user_id', $user->id)->where('status', 'outstanding')->get()
                ->sum(fn (StaffCashAdvance $advance) => max(0, $advance->amount - $advance->settled_amount));
            $cashAdvance = $cutoff === 'second' ? min($outstandingAdvance, max(0, $gross - $nonAdvanceDeductions)) : 0;
            $deductions = $nonAdvanceDeductions + $cashAdvance;

            return [
                'id'=>$user->id, 'name'=>$user->name, 'email'=>$user->email, 'phone'=>$user->phone,
                'profile_photo_url'=>$user->profile_photo_url,
                'attendance_reference_configured'=>filled($user->attendance_reference_photo_path),
                'attendance_reference_captured_at'=>$user->attendance_reference_captured_at,
                'pin_configured'=>filled($user->attendance_pin_hash),
                'roles'=>$user->roles->pluck('name')->values(),
                'records'=>$serializedRows,
                'today_record'=>($todayRecord = $todayRecords->get($user->id)) ? $this->attendanceRecordPayload($todayRecord) : null,
                'compensation'=>$manager ? $profile : null,
                'summary'=>[
                    'present_days'=>$presentDays, 'full_days'=>$fullDays, 'half_days'=>$halfDays, 'absent_days'=>$rows->where('status', 'absent')->count(),
                    'leave_days'=>$rows->where('status', 'leave')->count(),
                    'late_days'=>$rows->where('late_minutes', '>', 0)->count(),
                    'late_minutes'=>$rows->sum('late_minutes'), 'worked_hours'=>round($rows->sum('worked_minutes') / 60, 2),
                    'overtime_hours'=>round($approvedOvertimeMinutes / 60, 2),
                    'pending_overtime_minutes'=>$rows->where('overtime_status', 'pending')->sum('overtime_minutes'),
                    'base_pay'=>round($base, 2), 'overtime_pay'=>round($overtime, 2),
                    'allowance'=>round($allowance, 2), 'late_deduction'=>round($late, 2),
                    'sss_deduction'=>round($sss, 2), 'philhealth_deduction'=>round($philhealth, 2),
                    'pagibig_deduction'=>round($pagibig, 2), 'cash_advance_deduction'=>round($cashAdvance, 2),
                    'other_deductions'=>round($otherDeductions, 2), 'deductions'=>round($deductions, 2),
                    'gross_pay'=>round($gross, 2),
                    'cash_advance_balance'=>round($outstandingAdvance, 2),
                    'net_pay'=>round(max(0, $gross - $deductions), 2),
                    'government_rule_version'=>$government['rule_version'],
                ],
            ];
        });

        $payrollRuns = Schema::hasTable('staff_payroll_disbursements')
            ? StaffPayrollDisbursement::with('user:id,name,email')
                ->whereBetween('pay_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->orderByDesc('pay_date')->orderBy('created_at')->get()
            : collect();
        $installationIncentives = Schema::hasTable('installation_incentive_pools')
            ? InstallationIncentivePool::with([
                'ticket:id,ticket_number,customer_id,registered_at',
                'ticket.customer:id,full_name,account_number',
                'allocations.user:id,name',
            ])->whereBetween('work_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
                ->latest('work_date')->get()
            : collect();

        return response()->json(['data'=>[
            'month'=>$month,
            'selected_cutoff'=>$cutoff,
            'cutoff_start'=>$start->toDateString(),
            'cutoff_end'=>$end->toDateString(),
            'can_manage_payroll'=>$canEditPayroll,
            'payroll_policy'=>[
                'first_cutoff'=>'21st of previous month through 4th',
                'first_release'=>'15th at 12:00 PM',
                'second_cutoff'=>'5th through 20th',
                'second_release'=>'30th at 12:00 PM (last day for short months)',
                'first_incentive_cutoff'=>'1st through 15th; released on the 15th',
                'second_incentive_cutoff'=>'16th through 30th; released on the 30th (last day for short months)',
                'timezone'=>'Asia/Manila',
            ],
            'payroll_runs'=>$payrollRuns,
            'installation_incentives'=>$installationIncentives,
            'employees'=>$employees,
        ]]);
    }

    private function attendanceRecordPayload(StaffAttendanceRecord $record): array
    {
        return [
            ...$record->toArray(),
            // A business date must remain the same Philippine calendar date;
            // serializing its Manila midnight as UTC shifts it to yesterday.
            'work_date' => $record->work_date?->toDateString(),
            'clocked_in_at' => $record->clocked_in_at?->copy()->utc()->toIso8601String(),
            'clocked_out_at' => $record->clocked_out_at?->copy()->utc()->toIso8601String(),
        ];
    }

    public function payrollPayslip(StaffPayrollDisbursement $payroll): JsonResponse
    {
        return response()->json(['data' => $payroll->load(['user:id,name,email', 'releaser:id,name', 'financialEntry'])]);
    }

    public function releasePayroll(Request $request, StaffPayrollDisbursement $payroll, CashDenominationService $denominations): JsonResponse
    {
        $data = $request->validate([
            'payment_method' => ['required', Rule::in(['cash', 'gcash', 'bank_bpi', 'bank_landbank'])],
            'reference' => ['required', 'string', 'max:100'],
            'cash_breakdown' => ['nullable', 'array', 'size:10'],
            'cash_breakdown.*.denomination' => ['required_with:cash_breakdown', 'integer', 'in:1000,500,200,100,50,20,10,5,1'],
            'cash_breakdown.*.kind' => ['required_with:cash_breakdown', Rule::in(['bill', 'coin'])],
            'cash_breakdown.*.count' => ['required_with:cash_breakdown', 'integer', 'min:0', 'max:100000'],
        ]);

        if ($data['payment_method'] === 'cash') {
            abort_unless(isset($data['cash_breakdown']), 422, 'Cash denominations are required for a cash salary release.');
            $data['cash_breakdown'] = $denominations->normalize($data['cash_breakdown']);
            $denominations->assertEqualsAmount($data['cash_breakdown'], (float) $payroll->net_pay);
        } else {
            $data['cash_breakdown'] = null;
        }

        $payroll = DB::transaction(function () use ($request, $payroll, $data): StaffPayrollDisbursement {
            $locked = StaffPayrollDisbursement::with('user:id,name,email')->lockForUpdate()->findOrFail($payroll->id);
            abort_if($locked->status === 'released' || $locked->financial_entry_id, 409, 'This salary has already been released.');
            abort_unless($locked->status === 'scheduled', 422, 'Only a scheduled payroll can be released.');
            abort_if(now('Asia/Manila')->startOfDay()->lt($locked->pay_date->copy()->startOfDay()), 422, 'Salary cannot be released before its scheduled payday.');

            $wallet = match ($data['payment_method']) {
                'cash' => 'cash', 'gcash' => 'gcash', 'bank_bpi' => 'bpi', 'bank_landbank' => 'landbank',
            };
            $entry = FinancialEntry::firstOrCreate(
                ['idempotency_key' => $locked->id],
                [
                    'type' => 'expense',
                    'category' => 'Employee Salary',
                    'description' => $locked->user->name.' salary · '.$locked->cutoff_start->toDateString().' to '.$locked->cutoff_end->toDateString(),
                    'amount' => $locked->net_pay,
                    'cash_breakdown' => $data['cash_breakdown'],
                    'entry_date' => now('Asia/Manila')->toDateString(),
                    'payment_method' => $data['payment_method'],
                    'effect_type' => 'expense',
                    'source_wallet' => $wallet,
                    'destination_wallet' => null,
                    'reference' => trim($data['reference']),
                    'notes' => 'Payroll '.$locked->id.'; gross '.number_format($locked->gross_pay, 2).'; deductions '.number_format($locked->total_deductions, 2).'; cash advance deduction '.number_format($locked->cash_advance_deduction, 2).'.',
                    'recorded_by' => $request->user()->id,
                ]
            );

            $locked->update([
                'status' => 'released',
                'released_at' => now('UTC'),
                'release_method' => $data['payment_method'],
                'release_reference' => trim($data['reference']),
                'released_by' => $request->user()->id,
                'financial_entry_id' => $entry->id,
            ]);
            $remainingAdvanceDeduction = (float) $locked->cash_advance_deduction;
            $advanceIds = ($locked->calculation_snapshot ?? [])['cash_advance_ids'] ?? [];
            $advances = StaffCashAdvance::query()->where('user_id', $locked->user_id)
                ->whereIn('id', $advanceIds)->where('status', 'outstanding')->orderBy('issued_at')->lockForUpdate()->get();
            foreach ($advances as $advance) {
                if ($remainingAdvanceDeduction <= 0) break;
                $outstanding = max(0, (float) $advance->amount - (float) $advance->settled_amount);
                $applied = min($outstanding, $remainingAdvanceDeduction);
                if ($applied <= 0) continue;
                $settled = round((float) $advance->settled_amount + $applied, 2);
                $isSettled = $settled >= round((float) $advance->amount, 2);
                $advance->update([
                    'settled_amount' => $settled,
                    'status' => $isSettled ? 'settled' : 'outstanding',
                    'payroll_disbursement_id' => $locked->id,
                    'settled_at' => $isSettled ? now('UTC') : null,
                ]);
                $remainingAdvanceDeduction = round($remainingAdvanceDeduction - $applied, 2);
            }
            abort_if($remainingAdvanceDeduction > 0.009, 409, 'Cash advance balances changed after payroll preparation. Recalculate this payroll before release.');
            \App\Models\InstallationIncentiveAllocation::where('payroll_disbursement_id', $locked->id)->update(['status' => 'paid']);

            return $locked->fresh(['user:id,name,email', 'releaser:id,name', 'financialEntry']);
        });

        return response()->json(['message' => 'Salary released and recorded in Daily Operations as an expense.', 'data' => $payroll]);
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
        // Carbon 3 returns fractional minutes. Attendance columns are integer
        // counters, so store only completed minutes consistently.
        $late = (int) floor(max(0, $scheduled->diffInMinutes($now, false)));
        $location = Schema::hasTable('staff_live_locations') ? StaffLiveLocation::where('user_id', $employee->id)->first() : null;
        $record = StaffAttendanceRecord::firstOrCreate(
            ['user_id'=>$employee->id, 'work_date'=>$now->toDateString()],
            ['clocked_in_at'=>now('UTC'), 'status'=>$late > 0 ? 'late' : 'present', 'late_minutes'=>$late, 'clock_in_latitude'=>$evidence['latitude']??$location?->latitude, 'clock_in_longitude'=>$evidence['longitude']??$location?->longitude, 'verification_method'=>$evidence['verification_method']??null, 'clock_in_photo_path'=>$evidence['photo_path']??null, 'clock_in_photo_captured_at'=>$evidence['photo_captured_at']??null, 'clock_in_ip_address'=>$evidence['ip_address']??null, 'clock_in_device'=>$evidence['device']??null]
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
        $worked = (int) floor(max(0, $record->clocked_in_at->copy()->utc()->diffInMinutes(now('UTC'), false)));
        $overtimeStart = Carbon::parse($now->toDateString().' 18:00:00', 'Asia/Manila');
        $overtime = (int) floor(max(0, $overtimeStart->diffInMinutes($now, false)));
        $overtimeStatus = $overtime > 0 ? 'pending' : 'not_applicable';
        $location = Schema::hasTable('staff_live_locations') ? StaffLiveLocation::where('user_id', $employee->id)->first() : null;
        $record->update(['clocked_out_at'=>now('UTC'), 'worked_minutes'=>$worked, 'overtime_minutes'=>$overtime, 'overtime_status'=>$overtimeStatus, 'approved_overtime_minutes'=>0, 'overtime_reviewed_by'=>null, 'overtime_reviewed_at'=>null, 'overtime_review_notes'=>null, 'clock_out_latitude'=>$evidence['latitude']??$location?->latitude, 'clock_out_longitude'=>$evidence['longitude']??$location?->longitude, 'verification_method'=>$evidence['verification_method']??$record->verification_method, 'clock_out_photo_path'=>$evidence['photo_path']??null, 'clock_out_photo_captured_at'=>$evidence['photo_captured_at']??null, 'clock_out_ip_address'=>$evidence['ip_address']??null, 'clock_out_device'=>$evidence['device']??null]);
        return response()->json(['message'=>'Clock-out recorded.', 'data'=>$record->fresh()]);
    }

    public function reviewOvertime(Request $request, StaffAttendanceRecord $attendance): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
        abort_unless($attendance->overtime_status === 'pending' && $attendance->overtime_minutes > 0, 422, 'Only pending overtime can be reviewed.');
        abort_if(
            StaffPayrollDisbursement::query()
                ->where('user_id', $attendance->user_id)
                ->whereDate('cutoff_start', '<=', $attendance->work_date)
                ->whereDate('cutoff_end', '>=', $attendance->work_date)
                ->exists(),
            422,
            'This attendance date is already included in a payroll run and can no longer be changed.'
        );

        $approved = $data['decision'] === 'approved';
        $attendance->update([
            'overtime_status' => $data['decision'],
            'approved_overtime_minutes' => $approved ? $attendance->overtime_minutes : 0,
            'overtime_reviewed_by' => $request->user()->id,
            'overtime_reviewed_at' => now(),
            'overtime_review_notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
        ]);

        return response()->json([
            'message' => $approved ? 'Overtime approved for payroll.' : 'Overtime rejected and excluded from payroll.',
            'data' => $attendance->fresh('overtimeReviewer:id,name'),
        ]);
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
        $data['cash_advance_deduction'] = 0;
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

    public function updateReferencePhoto(Request $request, User $user, StaffProfilePhotoService $photos): JsonResponse
    {
        abort_if($user->hasRole('super_admin'), 422, 'Super Administrators are not included in attendance.');
        $request->validate(['photo'=>['required','image','mimes:jpeg,jpg,png,webp','max:4096','dimensions:min_width=128,min_height=128']]);
        $updated=$photos->replace($user,$request->file('photo'));
        return response()->json(['message'=>'Attendance reference/profile photo updated.','data'=>['profile_photo_url'=>$updated->profile_photo_url]]);
    }

    public function photo(Request $request, StaffAttendanceRecord $attendance, string $type)
    {
        $path = $type === 'clock-in' ? $attendance->clock_in_photo_path : $attendance->clock_out_photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Attendance photo not found.');
        StaffAttendancePhotoAudit::create(['attendance_record_id'=>$attendance->id,'actor_id'=>$request->user()->id,'event'=>'photo_viewed','photo_type'=>$type,'ip_address'=>$request->ip()]);

        return Storage::disk('local')->response($path, null, ['Cache-Control'=>'private, no-store']);
    }

    public function referencePhoto(Request $request, User $user)
    {
        abort_if($user->hasRole('super_admin'), 404);
        $path = $user->attendance_reference_photo_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'Attendance reference photo not found.');
        Log::info('Attendance reference photo viewed', ['employee_id'=>$user->id,'actor_id'=>$request->user()->id,'ip_address'=>$request->ip()]);

        return Storage::disk('local')->response($path, null, ['Cache-Control'=>'private, no-store']);
    }

    public function resetReferencePhoto(Request $request, User $user): JsonResponse
    {
        abort_if($user->hasRole('super_admin'), 422, 'Super Administrators are not included in attendance.');
        $path = $user->attendance_reference_photo_path;
        $user->forceFill(['attendance_reference_photo_path'=>null,'attendance_reference_captured_at'=>null])->save();
        if ($path) Storage::disk('local')->delete($path);
        Log::warning('Attendance reference photo reset', ['employee_id'=>$user->id,'actor_id'=>$request->user()->id,'ip_address'=>$request->ip()]);

        return response()->json(['message'=>'Attendance reference reset. The next successful time-in photo becomes the new reference.']);
    }
}
