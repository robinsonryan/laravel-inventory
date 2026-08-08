<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RobinsonRyan\Inventory\Support\SchemaSupport;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('inventory.tables.item_substitutions', 'item_substitutions'), function (Blueprint $table): void {
            SchemaSupport::uuidPrimary($table);
            $table->uuid('item_id');
            $table->uuid('substitute_item_id');
            $table->integer('priority')->default(0);
            $table->decimal('ratio', 10, 4)->default(1.0000);
            $table->boolean('is_bidirectional')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('item_id')
                ->references('id')
                ->on(config('inventory.tables.items', 'items'))
                ->onDelete('cascade');

            $table->foreign('substitute_item_id')
                ->references('id')
                ->on(config('inventory.tables.items', 'items'))
                ->onDelete('cascade');

            $table->unique(['item_id', 'substitute_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.item_substitutions', 'item_substitutions'));
    }
};
