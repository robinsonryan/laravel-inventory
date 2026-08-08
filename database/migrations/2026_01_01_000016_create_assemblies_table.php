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
        Schema::create(config('inventory.tables.assemblies', 'assemblies'), function (Blueprint $table): void {
            SchemaSupport::uuidPrimary($table);
            $table->uuid('item_id');
            $table->uuid('transformation_id');
            $table->string('lot_number')->nullable();
            $table->string('serial_number')->nullable();
            $table->decimal('quantity_produced', 10, 4);
            $table->dateTime('production_date');
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('item_id')
                ->references('id')
                ->on(config('inventory.tables.items', 'items'))
                ->onDelete('cascade');

            $table->foreign('transformation_id')
                ->references('id')
                ->on(config('inventory.tables.transformations', 'transformations'))
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.assemblies', 'assemblies'));
    }
};
