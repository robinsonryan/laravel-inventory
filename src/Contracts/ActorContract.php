<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Contracts;

/**
 * Contract for models that represent actors who perform
 * inventory actions (for audit trail purposes).
 */
interface ActorContract
{
    /**
     * Get the unique identifier for this actor.
     */
    public function getActorKey(): string;

    /**
     * Get a display name for this actor.
     */
    public function getActorName(): string;
}
