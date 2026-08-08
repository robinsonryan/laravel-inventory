<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Inventory\Support\TagValueInstaller;
use RobinsonRyan\Inventory\Support\TaxonTables;

/**
 * Installs the tag values the package's own code writes and reads.
 *
 * These are structural, not sample data: taxon validates a tag value against the
 * definition's child rows, and an incomplete child list rejects every value it
 * does not contain. Dated after every taxon migration (2024_01_*) so the tag
 * tables exist, and after every inventory table migration (2026_0[12]_*).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tagsTable = TaxonTables::tags();

        // Dating this after taxon's migrations only orders them while both sets
        // keep their original filenames. A host that publishes taxon's
        // migrations gets them re-dated to publish time by default on Laravel 11
        // and 12, which lands them *after* this file — and a host may publish
        // this package's migrations without publishing taxon's at all. Failing
        // here would leave this migration pending for good: every retry would
        // hit the same missing table and never reach the one that creates it.
        if (! Schema::hasTable($tagsTable)) {
            Log::warning(sprintf(
                'Inventory tag values were not installed: the tag table "%s" does not exist yet. '
                .'Run the taxon migrations, then `php artisan inventory:sync-tag-values` to load them.',
                $tagsTable,
            ));

            return;
        }

        TagValueInstaller::install();
    }

    public function down(): void
    {
        // Left in place deliberately. The value tags may be referenced by
        // taggables rows belonging to tables this migration did not create, and
        // deleting them would cascade those references away.
    }
};
