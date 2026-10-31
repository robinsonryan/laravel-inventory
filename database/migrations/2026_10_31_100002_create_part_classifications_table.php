<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Inventory\Support\SchemaSupport;
use RobinsonRyan\Inventory\Support\TableResolver;

return new class extends Migration
{
    /**
     * `tag_id` has no foreign key because taxon's tables may not exist yet: a host
     * that publishes taxon's migrations after this package's sorts them later,
     * and the seed migration's docblock has why that must not wedge `migrate`.
     * ManageCategories re-points these rows before it deletes a merged category.
     */
    public function up(): void
    {
        $table = TableResolver::resolve('part_classifications', 'part_classifications');

        Schema::create($table, function (Blueprint $blueprint) use ($table): void {
            SchemaSupport::uuidPrimary($blueprint);
            $blueprint->uuid('tenant_id')->nullable()->index();
            $blueprint->foreignUuid('item_id')
                ->constrained(config('inventory.tables.items', 'items'));
            $blueprint->string('axis');
            $blueprint->uuid('tag_id')->nullable()->index();
            $blueprint->text('proposed_value')->nullable();
            $blueprint->string('status')->index();
            $blueprint->decimal('confidence', 5, 4)->default(0);
            $blueprint->string('method');
            $blueprint->string('confirmed_by_type')->nullable();
            $blueprint->string('confirmed_by_id')->nullable();
            $blueprint->timestamp('confirmed_at')->nullable();
            $blueprint->timestamp('vetoed_at')->nullable();
            $blueprint->string('vetoed_by_type')->nullable();
            $blueprint->string('vetoed_by_id')->nullable();
            $blueprint->text('note')->nullable();
            $blueprint->uuid('replaced_by_id')->nullable();
            $blueprint->timestamps();

            $blueprint->index(['item_id', 'axis']);
            $blueprint->foreign('replaced_by_id')->references('id')->on($table);
        });

        DB::statement(
            "create unique index part_classifications_one_confirmed_category on {$table} (item_id) "
            ."where axis = 'category' and status = 'confirmed'",
        );

        DB::statement(
            "create unique index part_classifications_one_confirmed_fitment on {$table} (item_id, tag_id) "
            ."where axis = 'fitment' and status = 'confirmed'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.part_classifications', 'part_classifications'));
    }
};
