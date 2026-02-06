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
            // Option grouping - allows options to be grouped for exclusive selection
            $table->string('option_group')->nullable()->after('display_sort_order');
            $table->boolean('option_group_exclusive')->default(false)->after('option_group');

            // Attributes set on vehicle when this option is selected
            $table->json('sets_vehicle_attribute')->nullable()->after('option_group_exclusive');
        });
    }

    public function down(): void
    {
        Schema::table(config('inventory.tables.kits', 'kits'), function (Blueprint $table): void {
            $table->dropColumn([
                'option_group',
                'option_group_exclusive',
                'sets_vehicle_attribute',
            ]);
        });
    }
};
