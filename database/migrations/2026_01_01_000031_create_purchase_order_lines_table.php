<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('inventory.tables.purchase_order_lines', 'purchase_order_lines'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');
            $table->uuid('item_id');
            $table->string('description')->nullable();
            $table->decimal('quantity', 10, 4);
            $table->decimal('unit_cost', 10, 4);
            $table->decimal('total', 10, 2);
            $table->decimal('quantity_received', 10, 4)->default(0);
            $table->decimal('quantity_cancelled', 10, 4)->default(0);
            $table->uuid('destination_location_id')->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('purchase_order_id');
            $table->index('item_id');

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on(config('inventory.tables.purchase_orders', 'purchase_orders'))
                ->onDelete('cascade');

            $table->foreign('destination_location_id')
                ->references('id')
                ->on(config('inventory.tables.locations', 'locations'))
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.purchase_order_lines', 'purchase_order_lines'));
    }
};
