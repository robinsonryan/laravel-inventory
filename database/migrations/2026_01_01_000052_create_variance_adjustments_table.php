<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('inventory.tables.variance_adjustments', 'variance_adjustments'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('cycle_count_id');
            $table->uuid('cycle_count_line_id');
            $table->uuid('item_id');
            $table->uuid('location_id');
            $table->decimal('quantity_before', 10, 4);
            $table->decimal('quantity_after', 10, 4);
            $table->decimal('variance', 10, 4);
            $table->decimal('cost_impact', 10, 2)->nullable();
            $table->uuid('stock_movement_id');
            $table->text('reason')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('approved_at');
            $table->timestamps();

            $table->index('cycle_count_id');
            $table->index('item_id');
            $table->index('location_id');
            $table->index('approved_at');

            $table->foreign('cycle_count_id')
                ->references('id')
                ->on(config('inventory.tables.cycle_counts', 'cycle_counts'))
                ->onDelete('cascade');

            $table->foreign('cycle_count_line_id')
                ->references('id')
                ->on(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'))
                ->onDelete('cascade');

            $table->foreign('location_id')
                ->references('id')
                ->on(config('inventory.tables.locations', 'locations'))
                ->onDelete('cascade');

            $table->foreign('stock_movement_id')
                ->references('id')
                ->on(config('inventory.tables.stock_movements', 'stock_movements'))
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.variance_adjustments', 'variance_adjustments'));
    }
};
