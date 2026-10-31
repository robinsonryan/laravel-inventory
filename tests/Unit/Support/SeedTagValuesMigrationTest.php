<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartFitmentDefinition;
use RobinsonRyan\Inventory\Reconciliation\TagDefinitions\CycleCountStatusDefinition;

function seedTagValuesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 3).'/database/migrations/2026_03_01_000001_seed_inventory_tag_values.php';

    return $migration;
}

function partTagValuesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_31_100005_install_part_tag_values.php';

    return $migration;
}

describe('the seed-tag-values migration', function (): void {
    it('installs every declared value when the tag tables are there', function (): void {
        DB::table('taggables')->delete();
        DB::table('tags')->delete();

        seedTagValuesMigration()->up();

        expect(CycleCountStatusDefinition::values())->toContain('in-progress');
    });

    it('skips the seed instead of wedging migrate when the tag table does not exist yet', function (): void {
        // A host that publishes this package's migrations but not taxon's — or
        // publishes taxon's afterwards, which re-dates them to publish time on a
        // default Laravel 11/12 app — leaves this seed sorted ahead of the table
        // it needs. Dying here would leave the migration pending for good, and
        // every retry would hit the same wall without ever reaching taxon's
        // create-table migration.
        Schema::drop('taggables');
        Schema::drop('tags');

        Log::spy();

        seedTagValuesMigration()->up();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'inventory:sync-tag-values'));
    });
});

describe('the part tag values migration', function (): void {
    it('installs fitment on a database that ran the original seed before fitment existed', function (): void {
        PartFitmentDefinition::tag()->children()->delete();

        partTagValuesMigration()->up();

        expect(PartFitmentDefinition::values())->toEqualCanonicalizing(['ford', 'gm', 'nissan']);
    });

    it('skips the seed instead of wedging migrate when the tag table does not exist yet', function (): void {
        Schema::drop('taggables');
        Schema::drop('tags');

        Log::spy();

        partTagValuesMigration()->up();

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, 'inventory:sync-tag-values'));
    });
});
