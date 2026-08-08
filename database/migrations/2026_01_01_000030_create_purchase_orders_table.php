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
        Schema::create(config('inventory.tables.purchase_orders', 'purchase_orders'), function (Blueprint $table) {
            SchemaSupport::uuidPrimary($table);
            $table->uuid('tenant_id')->nullable()->index();
            $table->uuid('vendor_id');
            $table->string('po_number');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('tax', 10, 2)->default(0);
            $table->decimal('shipping', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->jsonb('shipping_address')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'po_number']);
            $table->index('vendor_id');
            $table->index('order_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('inventory.tables.purchase_orders', 'purchase_orders'));
    }
};
