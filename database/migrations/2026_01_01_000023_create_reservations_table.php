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
        Schema::create(config('inventory.tables.reservations', 'reservations'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->index();
            $table->uuid('item_id');
            $table->uuid('stock_id')->nullable();
            $table->uuid('location_id')->nullable();
            $table->string('job_type');
            $table->string('job_id');
            $table->string('line_id')->nullable();
            $table->decimal('quantity', 10, 4);
            $table->timestamp('reserved_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('item_id');
            $table->index(['job_type', 'job_id']);
            $table->index('expires_at');
            $table->index('reserved_at');

            $table->foreign('stock_id')
                ->references('id')
                ->on(config('inventory.tables.stock', 'stock'))
                ->onDelete('set null');

            $table->foreign('location_id')
                ->references('id')
                ->on(config('inventory.tables.locations', 'locations'))
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.reservations', 'reservations'));
    }
};
