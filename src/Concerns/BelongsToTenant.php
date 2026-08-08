<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Records which tenant owns a row. Does NOT isolate tenants.
 *
 * This trait stamps the tenant column on create and stops there. It adds no
 * global scope, so reads return every tenant's rows, and the reporting module
 * queries tables directly and never sees this trait at all.
 *
 * That is a deliberate position, not an oversight: enforcing isolation through
 * a tenant column is a promise every future query has to keep, and this package
 * has no host application that needs it yet. The schema is already shaped for
 * it — ten tables carry the column and every write fills it — so adding read
 * filtering later is additive, with no backfill and no data migration. See
 * QUEUE.md.
 *
 * A host that needs isolation today has `forTenant()` below, its own global
 * scopes, or a database per tenant.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    /**
     * Stamp the owning tenant on create, when the caller has not set one.
     */
    public static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model): void {
            if (! $model->getAttribute(static::getTenantColumn())) {
                $model->setAttribute(static::getTenantColumn(), static::getCurrentTenantId());
            }
        });
    }

    /**
     * Scope a query to a specific tenant.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function scopeForTenant(Builder $query, ?string $tenantId): Builder
    {
        if ($tenantId === null) {
            return $query;
        }

        return $query->where(static::getTenantColumn(), $tenantId);
    }

    /**
     * Get the tenant column name.
     */
    public static function getTenantColumn(): string
    {
        return config('inventory.tenant.column', 'tenant_id');
    }

    /**
     * Get the current tenant ID from the resolver.
     */
    protected static function getCurrentTenantId(): ?string
    {
        $resolver = app()->bound(\RobinsonRyan\Inventory\Contracts\TenantResolver::class)
            ? app(\RobinsonRyan\Inventory\Contracts\TenantResolver::class)
            : null;

        return $resolver?->getCurrentTenantId();
    }
}
