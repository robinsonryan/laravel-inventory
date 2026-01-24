<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('inventory.tables.cycle_counts', 'cycle_counts'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable()->index();
            $table->string('count_number');
            $table->uuid('location_id')->nullable();
            $table->date('scheduled_date')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'count_number']);
            $table->index('scheduled_date');
            $table->index('completed_at');

            $table->foreign('location_id')
                ->references('id')
                ->on(config('inventory.tables.locations', 'locations'))
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.cycle_counts', 'cycle_counts'));
    }
};
