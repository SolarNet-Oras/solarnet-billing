<?php

namespace App\Support;

use LogicException;

class TenantRuntimeNamespace
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function cacheKey(string $key): string
    {
        return $this->prefix('cache').':'.ltrim($key, ':');
    }

    public function queue(string $queue = 'default'): string
    {
        return $this->prefix('queue').':'.trim($queue ?: 'default');
    }

    public function storagePath(string $path): string
    {
        $safePath = ltrim(str_replace('..', '', $path), '/\\');

        return 'tenants/'.$this->tenantId().'/'.$safePath;
    }

    private function prefix(string $kind): string
    {
        return 'tenant:'.$this->tenantId().':'.$kind;
    }

    private function tenantId(): string
    {
        return $this->currentTenant->id()
            ?? throw new LogicException('A tenant context is required for runtime namespacing.');
    }
}
