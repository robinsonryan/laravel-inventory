<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('inventory.tables.stock_movements', 'stock_movements'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->index();
            $table->uuid('item_id');
            $table->uuid('from_location_id')->nullable();
            $table->uuid('to_location_id')->nullable();
            $table->decimal('quantity', 10, 4);
            $table->decimal('cost', 10, 4)->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->string('lot_number')->nullable();
            $table->string('serial_number')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('item_id');
            $table->index(['reference_type', 'reference_id']);
            $table->index(['actor_type', 'actor_id']);
            $table->index('created_at');

            $table->foreign('from_location_id')
                ->references('id')
                ->on(config('inventory.tables.locations', 'locations'))
                ->onDelete('set null');

            $table->foreign('to_location_id')
                ->references('id')
                ->on(config('inventory.tables.locations', 'locations'))
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.stock_movements', 'stock_movements'));
    }
};
