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
        Schema::create(config('inventory.tables.receipts', 'receipts'), function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('purchase_order_id');
            $table->string('receipt_number');
            $table->timestamp('received_at');
            $table->uuid('received_by')->nullable();
            $table->text('notes')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'receipt_number']);
            $table->index('purchase_order_id');

            $table->foreign('purchase_order_id')
                ->references('id')
                ->on(config('inventory.tables.purchase_orders', 'purchase_orders'))
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.receipts', 'receipts'));
    }
};
