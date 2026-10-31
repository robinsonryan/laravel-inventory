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
     * The two partial indexes are the invariant, so they are raw: the database
     * refuses a second confirmed match however a row was written. NULLS NOT
     * DISTINCT makes a vendor item's one open proposal with no part count too.
     */
    public function up(): void
    {
        $table = TableResolver::resolve('part_matches', 'part_matches');

        Schema::create($table, function (Blueprint $table): void {
            SchemaSupport::uuidPrimary($table);
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignUuid('vendor_item_id')
                ->index()
                ->constrained(config('vendor.tables.vendor_items', 'vendor_items'));
            $table->foreignUuid('item_id')
                ->nullable()
                ->index()
                ->constrained(config('inventory.tables.items', 'items'));
            $table->string('status')->index();
            $table->decimal('confidence', 5, 4)->default(0);
            $table->string('method');
            $table->jsonb('new_part')->nullable();
            $table->string('confirmed_by_type')->nullable();
            $table->string('confirmed_by_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('vetoed_at')->nullable();
            $table->string('vetoed_by_type')->nullable();
            $table->string('vetoed_by_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        DB::statement(
            "create unique index part_matches_one_confirmed_per_vendor_item on {$table} (vendor_item_id) "
            ."where status = 'confirmed'",
        );

        DB::statement(
            "create unique index part_matches_one_proposal_per_pairing on {$table} (vendor_item_id, item_id) "
            ."nulls not distinct where status = 'proposed'",
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.part_matches', 'part_matches'));
    }
};
