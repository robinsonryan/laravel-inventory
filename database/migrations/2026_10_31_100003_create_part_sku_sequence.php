<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `if not exists` because `migrate:fresh` wipes tables and leaves sequences.
     */
    public function up(): void
    {
        DB::statement('create sequence if not exists part_sku_seq');
    }

    public function down(): void
    {
        DB::statement('drop sequence if exists part_sku_seq');
    }
};
