<?php

namespace App\Models\Concerns;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

trait BelongsToTenantThroughParent
{
    abstract public function tenantOwnershipRelation(): string;

    public static function bootBelongsToTenantThroughParent(): void
    {
        static::addGlobalScope('tenant_parent', function (Builder $builder): void {
            if (! self::tenantParentEnforcementEnabled()) {
                return;
            }

            $tenantId = app(CurrentTenant::class)->id();
            if (! $tenantId) {
                $builder->whereRaw('1 = 0');

                return;
            }

            $relation = $builder->getModel()->tenantOwnershipRelation();
            $builder->whereHas($relation, fn (Builder $parent): Builder => $parent->forTenant($tenantId));
        });

        static::saving(function (Model $model): void {
            if (! self::tenantParentEnforcementEnabled()) {
                return;
            }

            $tenantId = app(CurrentTenant::class)->id();
            if (! $tenantId) {
                throw new LogicException('A tenant context is required for descendant records.');
            }

            $relation = $model->tenantOwnershipRelation();
            $parent = $model->{$relation}()->withoutGlobalScopes()->first();

            if (! $parent || $parent->getAttribute('tenant_id') !== $tenantId) {
                throw new LogicException('Cross-tenant descendant mutation was blocked.');
            }
        });
    }

    private static function tenantParentEnforcementEnabled(): bool
    {
        return (bool) config('saas.enabled')
            && (bool) config('saas.domains_enabled')
            && config('saas.enforcement_mode') === 'enforced';
    }
}
