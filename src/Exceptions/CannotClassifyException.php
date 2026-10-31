<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Exceptions;

use DomainException;

/**
 * A fitment or category was refused. Nothing has changed when this is raised.
 */
final class CannotClassifyException extends DomainException
{
    private const AMBIGUOUS_CATEGORY = 1;

    public static function unknownFitment(string $value): self
    {
        return new self("\"{$value}\" is not a make a part can fit.");
    }

    public static function newCategoryNeedsReview(string $value): self
    {
        return new self("\"{$value}\" is not a category yet, and a new category always goes to review.");
    }

    public static function ambiguousCategory(string $value): self
    {
        return new self(
            "\"{$value}\" names more than one category, so a person has to say which.",
            self::AMBIGUOUS_CATEGORY,
        );
    }

    public static function notACategory(string $value): self
    {
        return new self("\"{$value}\" is not a part category.");
    }

    public static function classifiedAttribute(string $attribute): self
    {
        return new self(
            "\"{$attribute}\" cannot be assigned. Propose and confirm a classification, "
            .'which keeps the tag and its record together.',
        );
    }

    public static function rejected(string $classificationId): self
    {
        return new self("Classification {$classificationId} was rejected. Only a person can reinstate it.");
    }

    public static function personChoseCategory(string $itemId): self
    {
        return new self("A person chose the category of part {$itemId}, so the rule cannot replace it.");
    }

    /**
     * Whether a surface should ask which category, and resend the choice as a tag.
     */
    public function isAmbiguousCategory(): bool
    {
        return $this->getCode() === self::AMBIGUOUS_CATEGORY;
    }
}
