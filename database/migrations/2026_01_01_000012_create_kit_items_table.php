<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('inventory.tables.kit_items', 'kit_items'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('kit_id');
            $table->uuid('item_id');
            $table->decimal('quantity', 10, 4)->default(1);
            $table->boolean('is_optional')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('kit_id')
                ->references('id')
                ->on(config('inventory.tables.kits', 'kits'))
                ->onDelete('cascade');

            $table->foreign('item_id')
                ->references('id')
                ->on(config('inventory.tables.items', 'items'))
                ->onDelete('cascade');

            $table->unique(['kit_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.kit_items', 'kit_items'));
    }
};
