<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Trait for models that belong to a tenant.
 *
 * @mixin Model
 *
 * @method static Builder query()
 */
trait BelongsToTenant
{
    /**
     * Boot the trait and add a global scope for tenant filtering.
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
