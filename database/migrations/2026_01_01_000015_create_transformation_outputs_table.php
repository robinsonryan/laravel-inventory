<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('inventory.tables.transformation_outputs', 'transformation_outputs'), function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('transformation_id');
            $table->uuid('item_id');
            $table->decimal('quantity', 10, 4);
            $table->timestamps();

            $table->foreign('transformation_id')
                ->references('id')
                ->on(config('inventory.tables.transformations', 'transformations'))
                ->onDelete('cascade');

            $table->foreign('item_id')
                ->references('id')
                ->on(config('inventory.tables.items', 'items'))
                ->onDelete('cascade');

            $table->unique(['transformation_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.transformation_outputs', 'transformation_outputs'));
    }
};
