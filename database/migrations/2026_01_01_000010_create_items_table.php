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
        Schema::create(config('inventory.tables.items', 'items'), function (Blueprint $table): void {
            SchemaSupport::uuidPrimary($table);
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('sku')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit_of_measure')->default('each');
            $table->decimal('cost', 10, 4)->nullable();
            $table->decimal('price', 10, 4)->nullable();
            $table->integer('min_stock_level')->nullable();
            $table->integer('max_stock_level')->nullable();
            $table->integer('lead_time_days')->nullable();
            $table->decimal('weight', 10, 4)->nullable();
            $table->json('dimensions')->nullable();
            $table->boolean('is_serialized')->default(false);
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.items', 'items'));
    }
};
