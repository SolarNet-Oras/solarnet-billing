<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SaasSubscriptionPlan;
use App\Models\SaasTenantApplication;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Throwable;

class SaasTenantApplicationController extends Controller
{
    public function plans(Request $request): JsonResponse
    {
        $this->assertAvailable($request);

        return response()->json(['data' => SaasSubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('amount')
            ->get(['id', 'code', 'name', 'description', 'amount', 'currency', 'billing_interval', 'trial_days', 'grace_days'])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertAvailable($request);
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'tenant_slug' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[a-zA-Z0-9-]+$/'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_phone' => ['required', 'string', 'min:7', 'max:40'],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
            'business_address' => ['required', 'string', 'max:2000'],
            'business_registration_number' => ['nullable', 'string', 'max:255'],
            'plan' => ['required', 'string', 'max:100'],
            'terms_accepted' => ['accepted'],
        ]);
        $slug = Str::slug($data['tenant_slug']);
        $email = Str::lower(trim($data['owner_email']));
        $plan = SaasSubscriptionPlan::query()->where('code', Str::lower($data['plan']))->where('is_active', true)->first();
        abort_if(! $plan, 422, 'The selected subscription plan is unavailable.');

        abort_if(Tenant::withTrashed()->where('slug', $slug)->exists(), 422, 'That tenant slug is unavailable.');
        abort_if(User::withTrashed()->whereRaw('LOWER(email) = ?', [$email])->exists(), 422, 'That email already belongs to an account.');
        abort_if(SaasTenantApplication::query()
            ->whereIn('status', ['awaiting_email_verification', 'pending_approval', 'approved'])
            ->where(fn ($query) => $query->where('tenant_slug', $slug)->orWhereRaw('LOWER(owner_email) = ?', [$email]))
            ->exists(), 422, 'An active application already uses that email or tenant slug.');

        $token = Str::random(64);
        $application = SaasTenantApplication::create([
            'plan_id' => $plan->id,
            'company_name' => trim($data['company_name']),
            'tenant_slug' => $slug,
            'owner_name' => trim($data['owner_name']),
            'owner_email' => $email,
            'owner_phone' => trim($data['owner_phone']),
            'password_hash' => Hash::make($data['password']),
            'business_address' => trim($data['business_address']),
            'business_registration_number' => filled($data['business_registration_number'] ?? null) ? trim($data['business_registration_number']) : null,
            'status' => 'awaiting_email_verification',
            'verification_token_hash' => hash('sha256', $token),
            'verification_expires_at' => now()->addHours(24),
            'ip_hash' => hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')),
            'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
        ]);
        $url = 'https://'.config('saas.domains.app').'/verify-application?token='.urlencode($token);
        try {
            Mail::raw(
                "Hello {$application->owner_name},\n\nVerify your ISP company application by opening this link within 24 hours:\n{$url}\n\nVerification submits the application for SolarNet Super Administrator review. It does not start a trial or create billing records.",
                fn ($message) => $message->to($application->owner_email)->subject('Verify your ISP company application')
            );
        } catch (Throwable) {
            $application->delete();
            abort(503, 'The verification email could not be sent. No application was retained; please try again later.');
        }

        return response()->json([
            'message' => 'Application saved. Check your email to verify it before administrator review.',
            'data' => ['id' => $application->id, 'status' => $application->status, 'email' => $application->owner_email],
        ], 201);
    }

    public function verify(Request $request): JsonResponse
    {
        $this->assertAvailable($request);
        $data = $request->validate(['token' => ['required', 'string', 'size:64']]);
        $application = SaasTenantApplication::query()
            ->where('verification_token_hash', hash('sha256', $data['token']))
            ->first();

        abort_if(! $application, 422, 'The verification link is invalid.');
        abort_if($application->status !== 'awaiting_email_verification', 422, 'This application is no longer awaiting verification.');
        abort_if(! $application->verification_expires_at || $application->verification_expires_at->isPast(), 422, 'The verification link has expired.');

        $application->update([
            'status' => 'pending_approval',
            'email_verified_at' => now(),
            'submitted_at' => now(),
            'verification_token_hash' => null,
            'verification_expires_at' => null,
        ]);

        return response()->json(['message' => 'Email verified. Your application is pending SolarNet administrator approval.', 'data' => ['id' => $application->id, 'status' => $application->status]]);
    }

    private function assertAvailable(Request $request): void
    {
        abort_unless(config('saas.registration_enabled'), 503, 'ISP company registration is not enabled yet.');
        abort_unless(mb_strtolower($request->getHost()) === mb_strtolower((string) config('saas.domains.app')), 404);
    }
}
