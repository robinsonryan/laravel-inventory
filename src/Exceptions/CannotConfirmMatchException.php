<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Exceptions;

use DomainException;

/**
 * A match was refused confirmation. Each refusal leaves the match untouched, so
 * a caller auto-confirming a batch can catch this and leave the proposal in the
 * review queue.
 */
final class CannotConfirmMatchException extends DomainException
{
    public static function inactivePart(string $itemId): self
    {
        return new self("Part {$itemId} is inactive, so nothing can be confirmed to it.");
    }

    public static function alreadyConfirmed(string $vendorItemId): self
    {
        return new self(
            "Vendor item {$vendorItemId} is already confirmed to another part. Only a person can change that.",
        );
    }

    public static function noPart(string $matchId): self
    {
        return new self("Match {$matchId} has no part yet. Create or choose the part, then propose it.");
    }

    public static function rejected(string $matchId): self
    {
        return new self("Match {$matchId} was rejected. Only a person can reinstate it.");
    }
}
