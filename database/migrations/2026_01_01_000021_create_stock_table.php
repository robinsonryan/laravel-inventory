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
        Schema::create(config('inventory.tables.stock', 'stock'), function (Blueprint $table) {
            SchemaSupport::uuidPrimary($table);
            $table->uuid('tenant_id')->index();
            $table->uuid('item_id');
            $table->uuid('location_id');
            $table->decimal('quantity', 10, 4)->default(0);
            $table->decimal('reserved_quantity', 10, 4)->default(0);
            $table->decimal('cost_basis', 10, 4)->nullable();
            $table->string('lot_number')->nullable();
            $table->string('serial_number')->nullable();
            $table->date('expiry_date')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['tenant_id', 'item_id', 'location_id', 'lot_number', 'serial_number'],
                'stock_unique_item_location',
            );

            $table->index(['item_id', 'location_id']);
            $table->index('expiry_date');

            $table->foreign('location_id')
                ->references('id')
                ->on(config('inventory.tables.locations', 'locations'))
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.stock', 'stock'));
    }
};
