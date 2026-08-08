<?php

declare(strict_types=1);

namespace RobinsonRyan\Inventory\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RobinsonRyan\Inventory\Catalog\TagDefinitions\ItemCategoryDefinition;
use RobinsonRyan\Inventory\Purchasing\TagDefinitions\PurchaseOrderStatusDefinition;
use RobinsonRyan\Inventory\Reconciliation\TagDefinitions\CycleCountStatusDefinition;
use RobinsonRyan\Inventory\Stock\TagDefinitions\LocationTypeDefinition;
use RobinsonRyan\Inventory\Stock\TagDefinitions\MovementTypeDefinition;
use RobinsonRyan\Inventory\Stock\TagDefinitions\ReservationStatusDefinition;
use RobinsonRyan\Taxon\TagDefinition;
use RuntimeException;

/**
 * Writes this package's declared tag values into the tag tables.
 *
 * Taxon decides whether a value is valid by reading the definition tag's child
 * rows, not by reading the `DEFAULT_VALUES` constant — and it fails *open* when
 * that child list is empty. A half-populated list is therefore worse than no
 * list at all: the first value written auto-creates itself and every other value
 * is then measured against a one-entry list and rejected. These values are
 * structural, so they are installed by a migration and can be reloaded at any
 * time with `inventory:sync-tag-values`.
 *
 * Every definition here declares `$global = true`, so the values are written
 * once with a null tenant rather than once per tenant.
 */
final class TagValueInstaller
{
    /**
     * Every tag definition this package owns.
     *
     * @var list<class-string<TagDefinition>>
     */
    public const DEFINITIONS = [
        ItemCategoryDefinition::class,
        LocationTypeDefinition::class,
        MovementTypeDefinition::class,
        ReservationStatusDefinition::class,
        PurchaseOrderStatusDefinition::class,
        CycleCountStatusDefinition::class,
    ];

    /**
     * Install every declared value for every definition.
     *
     * Idempotent: re-running only creates what is missing.
     *
     * @return array<class-string<TagDefinition>, int> definition class => values created
     */
    public static function install(): array
    {
        $created = [];

        foreach (self::DEFINITIONS as $definition) {
            $created[$definition] = self::installDefinition($definition);
        }

        return $created;
    }

    /**
     * Install one definition's declared values.
     *
     * Reads the values already present, then writes the remainder in a single
     * statement. Going value-by-value through `firstOrCreateValue()` re-resolves
     * the parent tag on every call — about 150 queries for the six definitions,
     * which costs ~70 ms on every one of the suite's several hundred tests, since
     * each rebuilds the database from scratch. The rows written here are exactly
     * the rows that method would have written.
     *
     * Read-then-write across a whole definition is a race: two app servers
     * running `migrate` on the same deploy would each see the list short and
     * each insert the remainder, doubling every value. Taxon's unique index on
     * (slug, parent_id, tenant_id) cannot stop that — these values are global,
     * so their tenant is null, and SQLite, PostgreSQL and MySQL all count nulls
     * in a unique index as distinct from one another. Nor can an upsert, for the
     * same reason. So the run serialises itself: inside a transaction it writes
     * the parent tag row *before* reading the children, which takes an exclusive
     * row lock on PostgreSQL and MySQL and puts SQLite into a write transaction
     * before it has read anything. A second installer blocks on that write and,
     * when it resumes, reads a children list that already includes the first
     * one's rows.
     *
     * @param  class-string<TagDefinition>  $definition
     * @return int the number of value tags created by this call
     */
    public static function installDefinition(string $definition): int
    {
        $declared = self::declaredValues($definition);

        return DB::transaction(function () use ($definition, $declared): int {
            $parent = $definition::tag();
            $tenantColumn = self::tenantColumn();

            // Claim the parent row. The value written is the value already
            // there — the point is the lock the write takes, not the change.
            DB::table(TaxonTables::tags())
                ->where('id', $parent->id)
                ->update(['updated_at' => $parent->getAttribute('updated_at')]);

            /** @var list<string> $existing */
            $existing = $parent->children()->pluck('slug')->all();

            $now = Carbon::now();
            $rows = [];

            foreach ($declared as $value) {
                // firstOrCreateValue() is the one taxon write path that does NOT
                // slugify its argument; every read path does. A value stored
                // unslugified is a value nothing can ever match again.
                $slug = Str::slug($value);

                if ($slug === '') {
                    throw new RuntimeException(
                        "Tag definition {$definition} declares the value \"{$value}\", which slugifies to an empty "
                        .'string and could never be stored or matched.',
                    );
                }

                if (in_array($slug, $existing, true)) {
                    continue;
                }

                $existing[] = $slug;

                $row = [
                    'name' => self::displayName($slug),
                    'slug' => $slug,
                    'parent_id' => $parent->id,
                    $tenantColumn => $parent->getAttribute($tenantColumn),
                    'assignable' => true,
                    'single_select' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (! $parent->getIncrementing()) {
                    $row['id'] = Str::uuid7()->toString();
                }

                $rows[] = $row;
            }

            if ($rows === []) {
                return 0;
            }

            $parent->newQuery()->insert($rows);

            return count($rows);
        });
    }

    /**
     * The wording a person sees for a value.
     *
     * `Str::headline()` reads well for all but one of the values this package
     * declares, so the derived name is the default and the map holds only the
     * exceptions — today, the one acronym, which headline renders as an ordinary
     * word ("Wip"). Slugs are unique across the six definitions, so one flat map
     * is enough; key it by definition as well if that ever stops being true.
     */
    private static function displayName(string $slug): string
    {
        $names = [
            'wip' => 'WIP',
        ];

        return $names[$slug] ?? Str::headline($slug);
    }

    /**
     * Read a definition's `DEFAULT_VALUES` constant.
     *
     * The constant is declared on the concrete definitions rather than on
     * taxon's abstract base, so it has to be read reflectively.
     *
     * A list this cannot read in full stops the install. Quietly skipping the
     * entries it does not understand would leave the definition half populated,
     * which is the one state this class exists to prevent.
     *
     * @param  class-string<TagDefinition>  $definition
     * @return list<string>
     */
    public static function declaredValues(string $definition): array
    {
        $constant = $definition.'::DEFAULT_VALUES';

        if (! defined($constant)) {
            throw new RuntimeException("Tag definition {$definition} declares no DEFAULT_VALUES constant.");
        }

        $values = constant($constant);

        if (! is_array($values)) {
            throw new RuntimeException("DEFAULT_VALUES on {$definition} is not a list of strings.");
        }

        $declared = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new RuntimeException(sprintf(
                    'Tag definition %s declares a value of type %s in DEFAULT_VALUES; every value must be a string.',
                    $definition,
                    get_debug_type($value),
                ));
            }

            $declared[] = $value;
        }

        return $declared;
    }

    /**
     * The column taxon stores a tag's tenant in.
     */
    private static function tenantColumn(): string
    {
        $column = config('taxon.tenant.column', 'tenant_id');

        return is_string($column) && $column !== '' ? $column : 'tenant_id';
    }
}
