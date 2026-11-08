<?php

namespace App\Services;

use App\Models\Tenant;

class SaasTenantWorkspaceActivationService
{
    /** @return array{allowed: bool, mode: string, reason: string} */
    public function evaluate(Tenant $tenant): array
    {
        $mode = (string) config('saas.workspace_activation_mode', 'staged');

        if ($tenant->is_primary) {
            return ['allowed' => false, 'mode' => $mode, 'reason' => 'primary_tenant_uses_private_billing_domain'];
        }

        if ($mode === 'active') {
            return ['allowed' => true, 'mode' => $mode, 'reason' => 'workspace_active'];
        }

        if ($mode === 'canary' && in_array($tenant->slug, $this->canarySlugs(), true)) {
            return ['allowed' => true, 'mode' => $mode, 'reason' => 'approved_canary_tenant'];
        }

        return [
            'allowed' => false,
            'mode' => $mode,
            'reason' => $mode === 'canary' ? 'tenant_not_in_canary_allowlist' : 'workspace_activation_staged',
        ];
    }

    /** @return list<string> */
    public function canarySlugs(): array
    {
        return collect(config('saas.workspace_canary_tenants', []))
            ->map(fn ($slug): string => mb_strtolower(trim((string) $slug)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
