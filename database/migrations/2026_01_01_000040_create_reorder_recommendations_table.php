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
        Schema::create(config('inventory.tables.reorder_recommendations', 'reorder_recommendations'), function (Blueprint $table) {
            SchemaSupport::uuidPrimary($table);
            $table->uuid('tenant_id')->index();
            $table->uuid('item_id');
            $table->uuid('vendor_id')->nullable();
            $table->decimal('current_quantity', 10, 4);
            $table->decimal('reserved_quantity', 10, 4);
            $table->decimal('available_quantity', 10, 4);
            $table->integer('min_stock_level')->nullable();
            $table->integer('recommended_quantity');
            $table->date('estimated_stockout_date')->nullable();
            $table->decimal('average_daily_usage', 10, 4)->nullable();
            $table->integer('lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('generated_at');
            $table->timestamp('actioned_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->uuid('purchase_order_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign('item_id')
                ->references('id')
                ->on(config('inventory.tables.items', 'items'))
                ->onDelete('cascade');

            $table->index(['tenant_id', 'item_id']);
            $table->index(['tenant_id', 'actioned_at', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.reorder_recommendations', 'reorder_recommendations'));
    }
};
