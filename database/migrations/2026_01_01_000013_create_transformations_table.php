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
        Schema::create(config('inventory.tables.transformations', 'transformations'), function (Blueprint $table): void {
            SchemaSupport::uuidPrimary($table);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.transformations', 'transformations'));
    }
};
