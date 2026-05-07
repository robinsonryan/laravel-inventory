<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('cycle_count_id');
            $table->uuid('item_id');
            $table->uuid('location_id');
            $table->uuid('stock_id')->nullable();
            $table->decimal('expected_quantity', 10, 4)->default(0);
            $table->decimal('counted_quantity', 10, 4)->nullable();
            $table->decimal('variance', 10, 4)->nullable();
            $table->string('lot_number')->nullable();
            $table->string('serial_number')->nullable();
            $table->uuid('counted_by')->nullable();
            $table->timestamp('counted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('cycle_count_id');
            $table->index('item_id');
            $table->index('location_id');
            $table->index('counted_at');

            $table->foreign('cycle_count_id')
                ->references('id')
                ->on(config('inventory.tables.cycle_counts', 'cycle_counts'))
                ->onDelete('cascade');

            $table->foreign('location_id')
                ->references('id')
                ->on(config('inventory.tables.locations', 'locations'))
                ->onDelete('cascade');

            $table->foreign('stock_id')
                ->references('id')
                ->on(config('inventory.tables.stock', 'stock'))
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.cycle_count_lines', 'cycle_count_lines'));
    }
};
