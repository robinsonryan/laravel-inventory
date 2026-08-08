<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Schema helpers shared by this package's migrations.
 */
final class SchemaSupport
{
    private static ?bool $supportsUuidV7 = null;

    /**
     * Declare a UUID primary key, defaulting it in the database only where the
     * database can actually generate one.
     *
     * `uuidv7()` is a PostgreSQL 18 built-in. Every model in this package
     * generates its own key through the HasUuid7 trait, so the database default
     * is only a safety net for rows inserted outside Eloquent (pivot writes via
     * `attach()`, for example). Applying it unconditionally made every migration
     * unrunnable on SQLite and MySQL — and on PostgreSQL below 18.
     *
     * The key is declared with an explicit primary() call rather than the fluent
     * `->primary()` column modifier. Fluent index modifiers only become commands
     * when the blueprint is compiled, so they land *after* every foreign key the
     * migration declared — and PostgreSQL rejects a self-referencing foreign key
     * whose target table has no key yet ("there is no unique constraint matching
     * given keys for referenced table"). Declaring it here puts the key first,
     * where a foreign key in the same Schema::create() can see it.
     */
    public static function uuidPrimary(Blueprint $table, string $column = 'id'): void
    {
        $definition = $table->uuid($column);
        $table->primary($column);

        if (self::supportsUuidV7()) {
            $definition->default(DB::raw('uuidv7()'));
        }
    }

    /**
     * Whether the active connection exposes a native `uuidv7()` function.
     */
    public static function supportsUuidV7(): bool
    {
        if (self::$supportsUuidV7 !== null) {
            return self::$supportsUuidV7;
        }

        $connection = DB::connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return self::$supportsUuidV7 = false;
        }

        try {
            $found = $connection->select("select 1 from pg_proc where proname = 'uuidv7' limit 1");
        } catch (Throwable) {
            return self::$supportsUuidV7 = false;
        }

        return self::$supportsUuidV7 = $found !== [];
    }

    /**
     * Reset the cached capability probe. Intended for tests that swap connections.
     */
    public static function flushCapabilityCache(): void
    {
        self::$supportsUuidV7 = null;
    }
}
