<?php

namespace App\Support;

use App\Models\Tenant;

class CurrentTenant
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->getKey();
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    public function resolveForWrite(): ?Tenant
    {
        if ($this->tenant) {
            return $this->tenant;
        }

        $this->tenant = Tenant::query()
            ->where('slug', config('saas.primary_tenant_slug'))
            ->where('is_primary', true)
            ->where('status', 'active')
            ->first();

        return $this->tenant;
    }
}
