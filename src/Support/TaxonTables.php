<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Support;

/**
 * The names of taxon's own tables.
 *
 * Taxon's tables are not listed in `inventory.tables`, so TableResolver does not
 * cover them, and a host is free to rename them in `taxon.tables`. Two callers
 * need them — the tag reader and the migration that seeds the tag values — and
 * neither may hardcode a name a host has moved.
 *
 * Nothing here reaches raw SQL: the names go through the query builder or the
 * schema builder, both of which quote and prefix them. This only has to survive
 * a config value that is not a usable string.
 */
final class TaxonTables
{
    public static function tags(): string
    {
        return self::resolve('tags', 'tags');
    }

    public static function taggables(): string
    {
        return self::resolve('taggables', 'taggables');
    }

    private static function resolve(string $key, string $default): string
    {
        $name = config("taxon.tables.{$key}", $default);

        return is_string($name) && $name !== '' ? $name : $default;
    }
}
