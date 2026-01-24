<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Concerns;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\Uuid;

/**
 * Trait for models using UUID7 primary keys.
 *
 * @mixin Model
 */
trait HasUuid7
{
    public function initializeHasUuid7(): void
    {
        $this->usesUniqueIds = true;
    }

    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }

    public function newUniqueId(): string
    {
        return (string) Uuid::v7();
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
