<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Contracts;

/**
 * Contract for models that represent work orders / jobs
 * that need inventory integration (reservations, consumption).
 */
interface JobContract
{
    /**
     * Get the unique identifier for this job.
     */
    public function getInventoryKey(): string;

    /**
     * Get the tenant ID for this job, if multi-tenancy is enabled.
     */
    public function getInventoryTenantId(): ?string;

    /**
     * Get the items that should be reserved for this job.
     *
     * @return iterable<array{item_id: string, quantity: int|float, line_id?: string}>
     */
    public function getItemsToReserve(): iterable;

    /**
     * Whether reservations should be held for this job.
     * Returns false if the job is completed or cancelled.
     */
    public function shouldHoldReservations(): bool;
}
