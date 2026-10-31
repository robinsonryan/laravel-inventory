<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Support;

use InvalidArgumentException;

/**
 * Resolves a configured table name for use inside raw SQL.
 *
 * The reporting queries interpolate table names directly into `DB::raw()`
 * fragments. Those names come from `config('inventory.tables.*')`, so a typo or
 * a hostile value in a host application's config would be spliced straight into
 * a statement. Validating the identifier here closes that hole and is what lets
 * the resulting expression stay a `literal-string` for static analysis.
 */
final class TableResolver
{
    /**
     * A bare SQL identifier: letters, digits and underscores, not digit-leading.
     */
    private const IDENTIFIER_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /**
     * @param  literal-string  $default
     * @return literal-string
     */
    public static function resolve(string $key, string $default): string
    {
        return self::validated("inventory.tables.{$key}", $default);
    }

    /**
     * The same, for a table `robinsonryan/vendor` owns.
     *
     * @param  literal-string  $default
     * @return literal-string
     */
    public static function vendor(string $key, string $default): string
    {
        return self::validated("vendor.tables.{$key}", $default);
    }

    /**
     * @param  literal-string  $default
     * @return literal-string
     */
    private static function validated(string $configKey, string $default): string
    {
        $name = config($configKey, $default);

        if (! is_string($name) || preg_match(self::IDENTIFIER_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(
                "Configured table name for {$configKey} is not a valid SQL identifier.",
            );
        }

        /** @var literal-string&non-falsy-string $name */
        return $name;
    }
}
