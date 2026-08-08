<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RobinsonRyan\Inventory\Catalog\Models\Item;
use RobinsonRyan\Inventory\Reconciliation\TagDefinitions\CycleCountStatusDefinition;
use RobinsonRyan\Inventory\Stock\TagDefinitions\LocationTypeDefinition;
use RobinsonRyan\Inventory\Support\TagValueInstaller;
use RobinsonRyan\Taxon\TagDefinition;

/**
 * A definition whose declared list contains something that is not a value at
 * all. Under-seeding is the one state this installer exists to prevent, so a
 * list it cannot read in full has to stop the install rather than shrink it.
 */
final class BrokenValuesTagDefinition extends TagDefinition
{
    /**
     * @var list<mixed>
     */
    public const DEFAULT_VALUES = ['fine', 42];

    public static string $slug = 'broken-values';

    public static string $name = 'Broken Values';

    public static bool $global = true;
}

/**
 * A definition declaring a value with no letters or digits in it, which
 * slugifies away to nothing and can therefore never be stored or matched.
 */
final class EmptySlugTagDefinition extends TagDefinition
{
    /**
     * @var list<string>
     */
    public const DEFAULT_VALUES = ['---'];

    public static string $slug = 'empty-slug';

    public static string $name = 'Empty Slug';

    public static bool $global = true;
}

describe('TagValueInstaller', function (): void {
    it('has already installed every declared value by the time a test runs', function (): void {
        foreach (TagValueInstaller::DEFINITIONS as $definition) {
            $declared = array_map(
                fn (string $value): string => Str::slug($value),
                TagValueInstaller::declaredValues($definition),
            );

            expect($definition::values())->toEqualCanonicalizing($declared);
        }
    });

    it('creates nothing on a second run', function (): void {
        expect(TagValueInstaller::install())->toBe(array_fill_keys(TagValueInstaller::DEFINITIONS, 0));
    });

    it('recreates a value that was deleted', function (): void {
        CycleCountStatusDefinition::removeValue('in-progress');

        expect(CycleCountStatusDefinition::values())->not->toContain('in-progress');

        $created = TagValueInstaller::installDefinition(CycleCountStatusDefinition::class);

        expect($created)->toBe(1)
            ->and(CycleCountStatusDefinition::values())->toContain('in-progress');
    });

    it('stores the slugified spelling, not the declared one', function (): void {
        // The cycle-count status is the one value with a two-word name; taxon
        // slugifies on every read, so an underscored spelling would be
        // unreachable for the life of the database.
        expect(CycleCountStatusDefinition::values())->toContain('in-progress')
            ->and(CycleCountStatusDefinition::values())->not->toContain('in_progress');
    });

    it('names each value for display', function (): void {
        $inProgress = CycleCountStatusDefinition::allValueTags()
            ->firstWhere('slug', 'in-progress');

        expect($inProgress?->name)->toBe('In Progress');
    });

    it('is re-runnable from the console', function (): void {
        CycleCountStatusDefinition::removeValue('in-progress');

        $this->artisan('inventory:sync-tag-values')
            ->expectsOutputToContain('cycle-count-status: 1 value(s) created, 4 already present')
            ->expectsOutputToContain('Tag values synced. 1 created in total.')
            ->assertExitCode(0);

        $this->artisan('inventory:sync-tag-values')
            ->expectsOutputToContain('Tag values synced. 0 created in total.')
            ->assertExitCode(0);
    });

    it('names the work-in-progress location type the way people write it', function (): void {
        // Str::headline() turns every other slug into something readable, but it
        // renders an acronym as an ordinary word. These names are user-visible.
        $wip = LocationTypeDefinition::allValueTags()->firstWhere('slug', 'wip');

        expect($wip?->name)->toBe('WIP');
    });

    it('claims the parent tag row before reading its children, all inside one transaction', function (): void {
        // Two app servers running `migrate` on the same deploy each read the
        // existing values, each find the list short, and each insert the
        // remainder. Taxon's unique index cannot stop them: these values carry a
        // null tenant, and every supported driver treats nulls in a unique index
        // as distinct. The installer has to serialise itself, by writing to the
        // parent row before it reads the children.
        CycleCountStatusDefinition::removeValue('in-progress');

        /** @var list<array{sql: string, level: int}> $log */
        $log = [];

        DB::listen(function (QueryExecuted $query) use (&$log): void {
            $log[] = ['sql' => $query->sql, 'level' => DB::transactionLevel()];
        });

        TagValueInstaller::installDefinition(CycleCountStatusDefinition::class);

        $indexOf = function (callable $matches) use ($log): ?int {
            foreach ($log as $index => $entry) {
                if ($matches($entry['sql'])) {
                    return $index;
                }
            }

            return null;
        };

        $claim = $indexOf(fn (string $sql): bool => str_starts_with($sql, 'update "tags"'));
        $readChildren = $indexOf(
            fn (string $sql): bool => str_contains($sql, 'from "tags"') && str_contains($sql, '"parent_id" = ?'),
        );
        $insert = $indexOf(fn (string $sql): bool => str_starts_with($sql, 'insert into "tags"'));

        expect($claim)->toBeInt()
            ->and($readChildren)->toBeInt()
            ->and($insert)->toBeInt()
            ->and($claim)->toBeLessThan($readChildren)
            ->and($readChildren)->toBeLessThan($insert);

        foreach ($log as $entry) {
            expect($entry['level'])->toBeGreaterThan(0);
        }
    });

    it('refuses a definition that declares something other than a string', function (): void {
        TagValueInstaller::declaredValues(BrokenValuesTagDefinition::class);
    })->throws(RuntimeException::class, 'declares a value of type int');

    it('refuses a declared value that slugifies away to nothing', function (): void {
        TagValueInstaller::installDefinition(EmptySlugTagDefinition::class);
    })->throws(RuntimeException::class, 'slugifies to an empty string');

    it('makes every declared value assignable to a record', function (): void {
        $item = Item::factory()->create();

        foreach (TagValueInstaller::declaredValues(RobinsonRyan\Inventory\Catalog\TagDefinitions\ItemCategoryDefinition::class) as $value) {
            $item->category = $value;

            expect($item->category)->toBe(Str::slug($value));
        }
    });
});
