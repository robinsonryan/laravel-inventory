<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Tests;

use RobinsonRyan\Inventory\Contracts\TenantResolver;

/**
 * A TenantResolver that reports one tenant for the whole suite.
 *
 * The package's tenant-scoped tables declare `tenant_id` NOT NULL, so something
 * has to supply one; binding this makes the suite exercise the tenancy path
 * rather than route around it.
 */
final class FixedTenantResolver implements TenantResolver
{
    public function __construct(private ?string $tenantId = null) {}

    public function getCurrentTenantId(): ?string
    {
        return $this->tenantId;
    }

    public function setCurrentTenantId(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function forTenant(?string $tenantId, callable $callback): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }
}
