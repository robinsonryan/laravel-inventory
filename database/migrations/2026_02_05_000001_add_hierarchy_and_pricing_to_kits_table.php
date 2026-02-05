<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('inventory.tables.kits', 'kits'), function (Blueprint $table): void {
            // Hierarchy - allow kits to have parent/child relationships (e.g., options under conversions)
            $table->uuid('parent_kit_id')->nullable()->after('item_id');

            // Pricing - kit price is independent of component item costs
            $table->decimal('retail_price', 10, 2)->nullable()->after('is_active');
            $table->decimal('dealer_price', 10, 2)->nullable()->after('retail_price');

            // Customer-facing configurator fields
            $table->boolean('is_customer_selectable')->default(false)->after('dealer_price');
            $table->text('display_description')->nullable()->after('is_customer_selectable');
            $table->integer('display_sort_order')->default(0)->after('display_description');

            // Self-referencing foreign key for hierarchy
            $table->foreign('parent_kit_id')
                ->references('id')
                ->on(config('inventory.tables.kits', 'kits'))
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table(config('inventory.tables.kits', 'kits'), function (Blueprint $table): void {
            $table->dropForeign(['parent_kit_id']);
            $table->dropColumn([
                'parent_kit_id',
                'retail_price',
                'dealer_price',
                'is_customer_selectable',
                'display_description',
                'display_sort_order',
            ]);
        });
    }
};
