<?php

namespace App\Services;

use App\Models\Tenant;
use Carbon\CarbonInterface;

class SaasTenantAccessService
{
    /** @return array{allowed: bool, reason: string, subscription_status: string|null} */
    public function evaluate(Tenant $tenant, ?CarbonInterface $at = null): array
    {
        $at ??= now();

        if ($tenant->subscription_exempt) {
            return ['allowed' => true, 'reason' => 'subscription_exempt', 'subscription_status' => null];
        }

        $subscription = $tenant->subscription;
        if (! $subscription) {
            return ['allowed' => false, 'reason' => 'subscription_missing', 'subscription_status' => null];
        }

        if ($subscription->status === 'trialing') {
            $allowed = $subscription->trial_ends_at && $subscription->trial_ends_at->greaterThanOrEqualTo($at);

            return [
                'allowed' => (bool) $allowed,
                'reason' => $allowed ? 'trial_active' : 'trial_expired',
                'subscription_status' => $subscription->status,
            ];
        }

        if ($subscription->status === 'active') {
            $allowed = ! $subscription->current_period_ends_at
                || $subscription->current_period_ends_at->greaterThanOrEqualTo($at);

            return [
                'allowed' => $allowed,
                'reason' => $allowed ? 'subscription_active' : 'subscription_expired',
                'subscription_status' => $subscription->status,
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'subscription_'.$subscription->status,
            'subscription_status' => $subscription->status,
        ];
    }
}
