<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Contracts;

/**
 * Contract for resolving the current tenant in a multi-tenant application.
 */
interface TenantResolver
{
    /**
     * Get the current tenant ID.
     */
    public function getCurrentTenantId(): ?string;

    /**
     * Set the current tenant ID.
     */
    public function setCurrentTenantId(?string $tenantId): void;

    /**
     * Execute a callback in the context of a specific tenant.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function forTenant(?string $tenantId, callable $callback): mixed;
}
