<?php

namespace App\Services;

use App\Models\SaasSubscriptionPlan;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantOnboardingAttempt;
use App\Models\TenantSubscription;
use App\Models\TenantSubscriptionEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SaasTenantOnboardingService
{
    /** @return array<string, mixed> */
    public function preview(string $slug, string $name, string $ownerEmail, string $planCode): array
    {
        $slug = Str::slug($slug);
        $ownerEmail = Str::lower(trim($ownerEmail));
        $planCode = Str::lower(trim($planCode));
        $tenant = Tenant::withTrashed()->where('slug', $slug)->first();
        $owner = User::query()->whereRaw('LOWER(email) = ?', [$ownerEmail])->first();
        $plan = SaasSubscriptionPlan::query()
            ->whereRaw('LOWER(code) = ?', [$planCode])
            ->where('is_active', true)
            ->first();
        $ownerConflict = $owner ? TenantMembership::query()
            ->where('user_id', $owner->id)
            ->where('is_owner', true)
            ->whereHas('tenant', fn ($query) => $query->where('slug', '!=', $slug))
            ->exists() : false;

        $errors = [];
        if ($slug === '' || strlen($slug) > 100) {
            $errors[] = 'invalid_tenant_slug';
        }
        if (trim($name) === '') {
            $errors[] = 'tenant_name_required';
        }
        if ($tenant?->deleted_at !== null) {
            $errors[] = 'tenant_slug_is_archived';
        } elseif ($tenant !== null) {
            $errors[] = 'tenant_slug_already_exists';
        }
        if (! $owner || ! $owner->is_active || $owner->deleted_at !== null) {
            $errors[] = 'active_owner_account_not_found';
        }
        if ($ownerConflict) {
            $errors[] = 'owner_already_owns_another_tenant';
        }
        if (! $plan) {
            $errors[] = 'active_subscription_plan_not_found';
        }

        return [
            'mode' => 'audit',
            'read_only' => true,
            'tenant' => [
                'slug' => $slug,
                'name' => trim($name),
                'will_be_primary' => false,
                'subscription_exempt' => false,
            ],
            'owner' => $owner?->only(['id', 'name', 'email', 'is_active']),
            'plan' => $plan?->only(['id', 'code', 'name', 'amount', 'currency', 'billing_interval', 'trial_days']),
            'trial_days' => $plan?->trial_days,
            'ready' => $errors === [],
            'errors' => $errors,
            'action' => $errors === [] ? 'ready_for_explicit_confirmation' : 'blocked_no_changes',
        ];
    }

    /** @return array<string, mixed> */
    public function execute(
        string $slug,
        string $name,
        string $ownerEmail,
        string $planCode,
        string $source = 'controlled_cli',
        ?string $actorUserId = null,
    ): array
    {
        $preview = $this->preview($slug, $name, $ownerEmail, $planCode);
        if (! $preview['ready']) {
            throw new RuntimeException('Tenant onboarding is not safe to execute: '.implode(', ', $preview['errors']));
        }

        $slug = $preview['tenant']['slug'];
        $ownerId = $preview['owner']['id'];
        $planId = $preview['plan']['id'];
        $now = now();

        return DB::transaction(function () use ($slug, $name, $ownerEmail, $ownerId, $planId, $now, $source, $actorUserId): array {
            if (Tenant::withTrashed()->where('slug', $slug)->lockForUpdate()->exists()) {
                throw new RuntimeException('Tenant slug was claimed before onboarding completed.');
            }

            $owner = User::query()->whereKey($ownerId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $plan = SaasSubscriptionPlan::query()->whereKey($planId)->where('is_active', true)->lockForUpdate()->firstOrFail();
            $tenant = Tenant::create([
                'slug' => $slug,
                'name' => trim($name),
                'contact_name' => $owner->name,
                'contact_email' => Str::lower(trim($ownerEmail)),
                'status' => 'active',
                'is_primary' => false,
                'subscription_exempt' => false,
                'metadata' => ['onboarding_source' => $source],
            ]);
            $membership = TenantMembership::create([
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
                'membership_role' => 'tenant_admin',
                'status' => 'active',
                'is_owner' => true,
            ]);
            $trialEndsAt = $now->copy()->addDays((int) $plan->trial_days);
            $subscription = TenantSubscription::create([
                'tenant_id' => $tenant->id,
                'plan_id' => $plan->id,
                'status' => 'trialing',
                'trial_starts_at' => $now,
                'trial_ends_at' => $trialEndsAt,
                'payment_status' => 'not_due',
                'metadata' => ['onboarding_source' => $source],
            ]);
            TenantSubscriptionEvent::create([
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'event_type' => 'trial_started',
                'to_status' => 'trialing',
                'actor_user_id' => $actorUserId ?? $owner->id,
                'source' => $source,
                'idempotency_key' => 'tenant-onboarding:'.$tenant->id,
                'context' => ['plan_code' => $plan->code, 'trial_days' => $plan->trial_days],
                'occurred_at' => $now,
            ]);
            TenantOnboardingAttempt::create([
                'tenant_id' => $tenant->id,
                'company_fingerprint' => hash('sha256', $slug),
                'email_hash' => hash_hmac('sha256', Str::lower(trim($ownerEmail)), (string) config('app.key')),
                'status' => 'completed',
                'context' => ['source' => $source, 'plan_code' => $plan->code],
            ]);

            return [
                'mode' => 'execute',
                'action' => 'tenant_onboarded_and_verified',
                'tenant' => $tenant->only(['id', 'slug', 'name', 'status', 'is_primary', 'subscription_exempt']),
                'owner_membership' => $membership->only(['user_id', 'membership_role', 'status', 'is_owner']),
                'subscription' => [
                    'id' => $subscription->id,
                    'plan_code' => $plan->code,
                    'status' => $subscription->status,
                    'trial_starts_at' => $subscription->trial_starts_at?->toIso8601String(),
                    'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                ],
                'core_billing_records_created' => 0,
            ];
        }, 3);
    }
}
