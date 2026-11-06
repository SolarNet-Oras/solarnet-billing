<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use LogicException;

trait BelongsToTenant
{
    public function initializeBelongsToTenant(): void
    {
        // Ownership is an authorization boundary, not public API data.
        $this->mergeHidden(['tenant_id']);
    }

    public static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model): void {
            if (! config('saas.ownership_auto_assignment', true)) {
                return;
            }

            if ($model->getAttribute('tenant_id')) {
                return;
            }

            $tenant = app(CurrentTenant::class)->resolveForWrite();
            if ($tenant) {
                $model->setAttribute('tenant_id', $tenant->getKey());

                return;
            }

            if (app()->environment('production')) {
                throw new LogicException('A primary tenant is required before creating tenant-owned records.');
            }
        });

        static::saving(function (Model $model): void {
            $currentTenantId = app(CurrentTenant::class)->id();
            if (self::tenantEnforcementEnabled()
                && $currentTenantId
                && $model->getAttribute('tenant_id') !== $currentTenantId) {
                throw new LogicException('Cross-tenant record mutation was blocked.');
            }

            $modelTenantId = $model->getAttribute('tenant_id');
            if (! $modelTenantId || ! method_exists($model, 'tenantOwnershipParents')) {
                return;
            }

            foreach ($model->tenantOwnershipParents() as $parent) {
                if (! ($parent['id'] ?? null)) {
                    continue;
                }

                $parentTenantId = DB::table($parent['table'])
                    ->where('id', $parent['id'])
                    ->value('tenant_id');
                if ($parentTenantId && $parentTenantId !== $modelTenantId) {
                    throw new LogicException('Cross-tenant parent relationship was blocked.');
                }

                if ($parentTenantId === null && app()->environment('production')) {
                    throw new LogicException('A tenant-owned record cannot reference an unowned parent.');
                }
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (! self::tenantEnforcementEnabled()) {
                return;
            }

            $tenantId = app(CurrentTenant::class)->id();
            if (! $tenantId) {
                // A tenant surface without a resolved tenant must never fall
                // back to an unrestricted query.
                $builder->whereRaw('1 = 0');

                return;
            }

            $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeForTenant(Builder $query, Tenant|string $tenant): Builder
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return $query->withoutGlobalScope('tenant')->where(
            $query->qualifyColumn('tenant_id'),
            $tenantId
        );
    }

    private static function tenantEnforcementEnabled(): bool
    {
        return (bool) config('saas.enabled')
            && (bool) config('saas.domains_enabled')
            && config('saas.enforcement_mode') === 'enforced';
    }
}
