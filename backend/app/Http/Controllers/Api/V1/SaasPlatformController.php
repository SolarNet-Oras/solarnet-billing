<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SaasSubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantSubscriptionEvent;
use App\Models\TenantSubscriptionPayment;
use App\Services\SaasTenantOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaasPlatformController extends Controller
{
    public function __construct(private readonly SaasTenantOnboardingService $onboarding) {}

    public function overview(): JsonResponse
    {
        $tenants = Tenant::query()->with([
            'subscription.plan:id,code,name,amount,currency,billing_interval,trial_days,grace_days',
            'memberships' => fn ($query) => $query->where('is_owner', true)->with('user:id,name,email'),
        ])->withCount('memberships')->orderByDesc('is_primary')->orderBy('name')->get()
            ->map(fn (Tenant $tenant) => [
                'id' => $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name,
                'status' => $tenant->status, 'is_primary' => $tenant->is_primary,
                'subscription_exempt' => $tenant->subscription_exempt,
                'memberships_count' => $tenant->memberships_count,
                'owner' => $tenant->memberships->first()?->user?->only(['id', 'name', 'email']),
                'subscription' => $tenant->subscription ? [
                    'id' => $tenant->subscription->id, 'status' => $tenant->subscription->status,
                    'payment_status' => $tenant->subscription->payment_status,
                    'trial_ends_at' => $tenant->subscription->trial_ends_at,
                    'current_period_ends_at' => $tenant->subscription->current_period_ends_at,
                    'next_payment_due_at' => $tenant->subscription->next_payment_due_at,
                    'plan' => $tenant->subscription->plan,
                ] : null,
            ]);

        return response()->json(['data' => [
            'summary' => [
                'tenants' => $tenants->count(), 'active' => $tenants->where('status', 'active')->count(),
                'trialing' => $tenants->filter(fn ($tenant) => data_get($tenant, 'subscription.status') === 'trialing')->count(),
                'suspended' => $tenants->where('status', 'suspended')->count(),
            ],
            'tenants' => $tenants,
            'plans' => SaasSubscriptionPlan::query()->orderBy('amount')->get(),
            'recent_payments' => TenantSubscriptionPayment::query()->with('tenant:id,slug,name')->latest()->limit(25)->get()
                ->map(fn ($payment) => [
                    'id' => $payment->id, 'tenant' => $payment->tenant?->only(['id', 'slug', 'name']),
                    'amount' => $payment->amount, 'currency' => $payment->currency, 'status' => $payment->status,
                    'provider' => $payment->provider, 'provider_reference' => $payment->provider_reference,
                    'verified_at' => $payment->verified_at, 'created_at' => $payment->created_at,
                ]),
        ]]);
    }

    public function previewTenant(Request $request): JsonResponse
    {
        $data = $this->tenantInput($request, false);
        return response()->json(['data' => $this->onboarding->preview($data['slug'], $data['name'], $data['owner_email'], $data['plan'])]);
    }

    public function storeTenant(Request $request): JsonResponse
    {
        $data = $this->tenantInput($request, true);
        $slug = Str::slug($data['slug']);
        abort_unless(hash_equals('CREATE TENANT '.Str::upper($slug), $data['confirmation']), 422, 'The tenant confirmation phrase is incorrect.');

        return response()->json([
            'message' => 'Tenant onboarded with an owner and trial subscription.',
            'data' => $this->onboarding->execute(
                $slug,
                $data['name'],
                $data['owner_email'],
                $data['plan'],
                'saas_platform_admin',
                $request->user('api')?->id,
            ),
        ], 201);
    }

    public function updatePlan(Request $request, SaasSubscriptionPlan $plan): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:1', 'max:999999.99'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['required', 'integer', 'min:0', 'max:90'],
            'is_active' => ['required', 'boolean'],
            'confirmation' => ['required', 'string', 'max:200'],
        ]);
        $confirmation = 'UPDATE SAAS PLAN '.Str::upper($plan->code);
        abort_unless(hash_equals($confirmation, $data['confirmation']), 422, 'The plan confirmation phrase is incorrect.');

        $plan->update([
            'name' => trim($data['name']),
            'description' => sprintf(
                '%d-day free trial, then PHP %s monthly with a %d-day grace period.',
                $data['trial_days'],
                number_format((float) $data['amount'], 2, '.', ','),
                $data['grace_days'],
            ),
            'amount' => round((float) $data['amount'], 2),
            'trial_days' => $data['trial_days'],
            'grace_days' => $data['grace_days'],
            'is_active' => $data['is_active'],
        ]);

        return response()->json([
            'message' => 'Subscription plan updated. Existing subscription periods and payments were not changed.',
            'data' => $plan->fresh(),
        ]);
    }

    public function updateTenantStatus(Request $request, Tenant $tenant): JsonResponse
    {
        abort_if($tenant->is_primary || $tenant->subscription_exempt, 422, 'The protected SolarNet tenant cannot be changed here.');
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'confirmation' => ['required', 'string']]);
        $confirmation = 'SET TENANT '.Str::upper($tenant->slug).' STATUS '.Str::upper($data['status']);
        abort_unless(hash_equals($confirmation, $data['confirmation']), 422, 'The tenant status confirmation phrase is incorrect.');

        DB::transaction(function () use ($request, $tenant, $data): void {
            $previous = $tenant->status;
            $tenant->update(['status' => $data['status']]);
            TenantSubscriptionEvent::create([
                'tenant_id' => $tenant->id, 'subscription_id' => $tenant->subscription?->id,
                'event_type' => 'tenant_status_changed', 'from_status' => $previous, 'to_status' => $data['status'],
                'actor_user_id' => $request->user('api')?->id, 'source' => 'saas_platform_admin',
                'context' => ['tenant_slug' => $tenant->slug], 'occurred_at' => now(),
            ]);
        }, 3);

        return response()->json(['message' => 'Tenant status updated.', 'data' => $tenant->fresh()]);
    }

    /** @return array{slug:string,name:string,owner_email:string,plan:string,confirmation?:string} */
    private function tenantInput(Request $request, bool $confirmation): array
    {
        return $request->validate([
            'slug' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'plan' => ['required', 'string', 'max:100'],
            'confirmation' => [$confirmation ? 'required' : 'nullable', 'string', 'max:200'],
        ]);
    }
}
