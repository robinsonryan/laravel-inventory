<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartCategoryDefinition;
use RobinsonRyan\Inventory\Matching\TagDefinitions\PartFitmentDefinition;
use RobinsonRyan\Inventory\Support\TagValueInstaller;
use RobinsonRyan\Inventory\Support\TaxonTables;

/**
 * Installs the two part definitions on a database that ran the original tag
 * migration before they existed. On a fresh one that migration already covered
 * them, and this creates nothing. A missing tag table skips it for the reason the
 * original gives.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(TaxonTables::tags())) {
            Log::warning(
                'Part tag values were not installed: the tag table does not exist yet. '
                .'Run the taxon migrations, then `php artisan inventory:sync-tag-values` to load them.',
            );

            return;
        }

        TagValueInstaller::installDefinition(PartCategoryDefinition::class);
        TagValueInstaller::installDefinition(PartFitmentDefinition::class);
    }

    public function down(): void
    {
        // Left in place, as the original tag migration does: the value tags may
        // still be referenced by taggables rows.
    }
};
