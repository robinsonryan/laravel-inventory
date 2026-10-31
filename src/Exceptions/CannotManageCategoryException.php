<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Exceptions;

use DomainException;

/**
 * A change to the category tree was refused. Nothing has changed when this is
 * raised.
 */
final class CannotManageCategoryException extends DomainException
{
    public static function notACategory(string $name): self
    {
        return new self("\"{$name}\" is not a category in the part tree.");
    }

    public static function mergeIntoItself(string $name, string $into): self
    {
        return new self("\"{$name}\" cannot be merged into \"{$into}\", which is itself or sits inside it.");
    }
}
