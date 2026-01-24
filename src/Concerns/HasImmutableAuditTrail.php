<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Concerns;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Trait for models that should not be updated or deleted after creation.
 *
 * @mixin Model
 */
trait HasImmutableAuditTrail
{
    /**
     * Boot the trait and prevent updates/deletes.
     */
    public static function bootHasImmutableAuditTrail(): void
    {
        static::updating(function (Model $model): void {
            if (config('inventory.rules.movement_immutable', true)) {
                throw new RuntimeException(
                    sprintf('Cannot update %s: records are immutable for audit trail integrity.', class_basename($model))
                );
            }
        });

        static::deleting(function (Model $model): void {
            if (config('inventory.rules.movement_immutable', true)) {
                throw new RuntimeException(
                    sprintf('Cannot delete %s: records are immutable for audit trail integrity.', class_basename($model))
                );
            }
        });
    }

    /**
     * Check if immutability is enforced.
     */
    public static function isImmutable(): bool
    {
        return config('inventory.rules.movement_immutable', true);
    }
}
