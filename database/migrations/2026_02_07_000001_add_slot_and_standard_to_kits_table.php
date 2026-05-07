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
            // Slot within an option group (e.g., "front", "rear") - allows one selection per slot
            $table->string('option_slot')->nullable()->after('option_group_exclusive');

            // Tags one option as the no-upgrade/standard choice within a group
            $table->boolean('is_standard')->default(false)->after('option_slot');
        });
    }

    public function down(): void
    {
        Schema::table(config('inventory.tables.kits', 'kits'), function (Blueprint $table): void {
            $table->dropColumn([
                'option_slot',
                'is_standard',
            ]);
        });
    }
};
