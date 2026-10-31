<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Exceptions;

use DomainException;

/**
 * A fitment or category was refused. Nothing has changed when this is raised.
 */
final class CannotClassifyException extends DomainException
{
    public static function unknownFitment(string $value): self
    {
        return new self("\"{$value}\" is not a make a part can fit.");
    }

    public static function newCategoryNeedsReview(string $value): self
    {
        return new self("\"{$value}\" is not a category yet, and a new category always goes to review.");
    }

    public static function rejected(string $classificationId): self
    {
        return new self("Classification {$classificationId} was rejected. Only a person can reinstate it.");
    }

    public static function personChoseCategory(string $itemId): self
    {
        return new self("A person chose the category of part {$itemId}, so the rule cannot replace it.");
    }
}
