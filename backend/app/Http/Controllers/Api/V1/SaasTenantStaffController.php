<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SaasTenantStaffAccessRequest;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Notifications\SaasTenantResetPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class SaasTenantStaffController extends Controller
{
    private const STAFF_ROLES = [
        'tenant_staff_admin',
        'tenant_collector',
        'tenant_technician',
        'tenant_collector_technician',
    ];

    public function requestPasswordReset(Request $request): JsonResponse
    {
        $data = $request->validate(['tenant' => ['required','string','max:100'], 'email' => ['required','email','max:255']]);
        $tenant = $this->tenant($data['tenant']);
        $user = User::query()->whereRaw('LOWER(email) = ?', [Str::lower(trim($data['email']))])->where('is_active', true)->first();
        $member = $tenant && $user && TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')->exists();
        if ($member) {
            $token = Password::broker()->createToken($user);
            $user->notify(new SaasTenantResetPassword($token, $tenant->slug));
        }
        return response()->json(['message' => 'If those active tenant details match, a secure reset link has been emailed.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant' => ['required','string','max:100'], 'email' => ['required','email','max:255'],
            'token' => ['required','string'], 'password' => ['required','confirmed',PasswordRule::min(10)->letters()->numbers()],
        ]);
        $tenant = $this->tenant($data['tenant']);
        $user = $tenant ? User::query()->whereRaw('LOWER(email) = ?', [Str::lower(trim($data['email']))])->first() : null;
        if (! $tenant || ! $user || ! TenantMembership::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('status', 'active')->exists()) {
            return response()->json(['message' => 'This password reset link is invalid.'], 422);
        }
        $credentials = collect($data)->only(['email','password','password_confirmation','token'])->all();
        $status = Password::broker()->reset($credentials, function (User $account, string $password): void {
            $account->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
        });
        return $status === Password::PASSWORD_RESET
            ? response()->json(['message' => 'Password reset. You can now sign in to your company workspace.'])
            : response()->json(['message' => __($status)], 422);
    }

    public function storeRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant' => ['required','string','max:100'], 'name' => ['required','string','max:255'],
            'email' => ['required','email','max:255'], 'phone' => ['nullable','string','max:40'],
            'requested_role' => ['sometimes','in:tenant_staff_admin,tenant_collector,tenant_technician,tenant_collector_technician'],
            'password' => ['required','confirmed',PasswordRule::min(10)->letters()->numbers()],
        ]);
        $tenant = $this->tenant($data['tenant']);
        if (! $tenant) return response()->json(['message' => 'The company login ID is unavailable.'], 422);
        $email = Str::lower(trim($data['email']));
        $accessRequest = SaasTenantStaffAccessRequest::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'email' => $email],
            ['name' => trim($data['name']), 'phone' => $data['phone'] ?? null, 'requested_role' => $data['requested_role'] ?? 'tenant_staff_admin', 'password_encrypted' => Crypt::encryptString($data['password']), 'status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'review_notes' => null]
        );
        return response()->json(['message' => 'Staff access request submitted to the Tenant Super Administrator.', 'request_id' => $accessRequest->id], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeTenantAdmin($request);
        $tenant = $request->attributes->get('saas_tenant');
        return response()->json(['data' => SaasTenantStaffAccessRequest::query()->where('tenant_id', $tenant->id)->orderByDesc('created_at')->get(['id','name','email','phone','requested_role','status','created_at','reviewed_at'])]);
    }

    public function members(Request $request): JsonResponse
    {
        $this->authorizeTenantAdmin($request);
        $tenant = $request->attributes->get('saas_tenant');

        $members = TenantMembership::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('is_owner', false)
            ->whereIn('membership_role', [...self::STAFF_ROLES, 'tenant_staff'])
            ->with('user:id,name,email,phone')
            ->orderBy('created_at')
            ->get()
            ->map(fn (TenantMembership $membership): array => [
                'id' => $membership->id,
                'name' => $membership->user?->name,
                'email' => $membership->user?->email,
                'phone' => $membership->user?->phone,
                'membership_role' => $membership->membership_role,
                'status' => $membership->status,
                'created_at' => $membership->created_at,
            ]);

        return response()->json(['data' => $members]);
    }

    public function updateMemberRole(Request $request, TenantMembership $membership): JsonResponse
    {
        $this->authorizeTenantAdmin($request);
        $this->authorizeManagedMembership($request, $membership);
        $data = $request->validate([
            'membership_role' => ['required', 'in:'.implode(',', self::STAFF_ROLES)],
        ]);

        $membership->update(['membership_role' => $data['membership_role']]);

        return response()->json(['message' => 'Staff role updated.']);
    }

    public function removeMember(Request $request, TenantMembership $membership): JsonResponse
    {
        $this->authorizeTenantAdmin($request);
        $this->authorizeManagedMembership($request, $membership);
        $membership->update(['status' => 'revoked']);

        return response()->json(['message' => 'Staff workspace access removed.']);
    }

    public function review(Request $request, SaasTenantStaffAccessRequest $accessRequest): JsonResponse
    {
        $this->authorizeTenantAdmin($request);
        $tenant = $request->attributes->get('saas_tenant');
        abort_unless($accessRequest->tenant_id === $tenant->id, 404);
        $data = $request->validate(['decision' => ['required','in:approve,reject'], 'notes' => ['nullable','string','max:1000']]);
        if ($accessRequest->status !== 'pending') return response()->json(['message' => 'This request was already reviewed.'], 409);

        DB::transaction(function () use ($request, $accessRequest, $tenant, $data): void {
            if ($data['decision'] === 'approve') {
                $user = User::withTrashed()->whereRaw('LOWER(email) = ?', [Str::lower($accessRequest->email)])->first();
                if (! $user) {
                    $user = User::create(['name' => $accessRequest->name, 'email' => $accessRequest->email, 'phone' => $accessRequest->phone, 'password' => Crypt::decryptString($accessRequest->password_encrypted), 'is_active' => true]);
                } elseif ($user->trashed()) {
                    abort(422, 'This email belongs to a deleted account and requires platform review.');
                }
                TenantMembership::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                    ['membership_role' => $accessRequest->requested_role, 'status' => 'active', 'is_owner' => false]
                );
            }
            $accessRequest->update(['status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $request->user('api')->id, 'reviewed_at' => now(), 'review_notes' => $data['notes'] ?? null]);
        });
        return response()->json(['message' => $data['decision'] === 'approve' ? 'Staff access granted.' : 'Staff access request rejected.']);
    }

    private function tenant(string $slug): ?Tenant
    {
        return Tenant::query()->where('slug', Str::lower(trim($slug)))->where('status', 'active')->where('is_primary', false)->first();
    }

    private function authorizeTenantAdmin(Request $request): void
    {
        abort_unless($request->attributes->get('saas_membership')?->membership_role === 'tenant_admin', 403, 'Tenant Super Administrator access is required.');
    }

    private function authorizeManagedMembership(Request $request, TenantMembership $membership): void
    {
        $tenant = $request->attributes->get('saas_tenant');
        abort_unless($membership->tenant_id === $tenant->id, 404);
        abort_if($membership->is_owner || $membership->membership_role === 'tenant_admin', 422, 'The Tenant Super Administrator cannot be changed or removed here.');
        abort_unless($membership->status === 'active', 409, 'This staff membership is not active.');
    }
}
