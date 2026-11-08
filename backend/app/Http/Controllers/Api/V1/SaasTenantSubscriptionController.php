<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Services\SaasSubscriptionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SaasTenantSubscriptionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */ $tenant = $request->attributes->get('saas_tenant');
        /** @var TenantMembership $membership */ $membership = $request->attributes->get('saas_membership');
        $subscription = $tenant->subscription()->with(['plan', 'payments' => fn ($query) => $query->latest()->limit(10)])->firstOrFail();
        $trialDays = $subscription->trial_ends_at ? (int) max(0, now()->startOfDay()->diffInDays($subscription->trial_ends_at->copy()->startOfDay(), false)) : 0;
        return response()->json(['data' => [
            'tenant' => $tenant->only(['id', 'name', 'slug']),
            'administrator' => ['role' => $membership->is_owner ? 'Tenant Super Administrator' : 'Tenant Staff', 'is_owner' => (bool) $membership->is_owner],
            'subscription' => $subscription->only(['id','status','payment_status','trial_starts_at','trial_ends_at','current_period_starts_at','current_period_ends_at','next_payment_due_at','last_successful_payment_at']),
            'plan' => $subscription->plan?->only(['id','code','name','description','amount','currency','billing_interval','trial_days','grace_days']),
            'trial_days_remaining' => $trialDays,
            'can_pay' => (bool) $membership->is_owner,
            'payments' => $subscription->payments->map->only(['id','amount','currency','status','provider_reference','verified_at','created_at']),
        ]]);
    }

    public function checkout(Request $request, SaasSubscriptionPaymentService $payments): JsonResponse
    {
        /** @var Tenant $tenant */ $tenant = $request->attributes->get('saas_tenant');
        /** @var TenantMembership $membership */ $membership = $request->attributes->get('saas_membership');
        abort_unless($membership->is_owner && $membership->membership_role === 'tenant_admin', 403, 'Only the Tenant Super Administrator can pay the company subscription.');
        return response()->json(['data' => $payments->checkout($tenant->subscription()->firstOrFail())]);
    }
}
