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
        Schema::create(config('inventory.tables.receipt_lines', 'receipt_lines'), function (Blueprint $table) {
            SchemaSupport::uuidPrimary($table);
            $table->uuid('receipt_id');
            $table->uuid('purchase_order_line_id');
            $table->uuid('item_id');
            $table->uuid('location_id');
            // A receipt line is the receiving desk's record of a delivery: what
            // turned up, how much, and where it was put. `quantity` IS the
            // received quantity — there is no second count to hold.
            //
            // Deliberately absent: a unit cost. Price is agreed on the purchase
            // order, not at the loading dock, and receiving staff have no way to
            // know it. Reports that need received value join back through
            // purchase_order_line_id to purchase_order_lines.unit_cost. The
            // ordered-versus-received reconciliation lives on that line too
            // (quantity vs quantity_received).
            $table->decimal('quantity', 10, 4);
            $table->string('lot_number')->nullable();
            $table->string('serial_number')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('receipt_id');
            $table->index('purchase_order_line_id');
            $table->index('item_id');
            $table->index('location_id');

            $table->foreign('receipt_id')
                ->references('id')
                ->on(config('inventory.tables.receipts', 'receipts'))
                ->onDelete('cascade');

            $table->foreign('purchase_order_line_id')
                ->references('id')
                ->on(config('inventory.tables.purchase_order_lines', 'purchase_order_lines'))
                ->onDelete('cascade');

            $table->foreign('location_id')
                ->references('id')
                ->on(config('inventory.tables.locations', 'locations'))
                ->onDelete('restrict');

            $table->foreign('stock_movement_id')
                ->references('id')
                ->on(config('inventory.tables.stock_movements', 'stock_movements'))
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.receipt_lines', 'receipt_lines'));
    }
};
