<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('finished_product_computed_prices')
            || !Schema::hasColumn('finished_product_computed_prices', 'finished_product_material_id')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `finished_product_computed_prices` ' .
            'MODIFY `finished_product_material_id` BIGINT UNSIGNED NULL'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('finished_product_computed_prices')
            || !Schema::hasColumn('finished_product_computed_prices', 'finished_product_material_id')) {
            return;
        }

        $hasNullRows = DB::table('finished_product_computed_prices')
            ->whereNull('finished_product_material_id')
            ->exists();

        if ($hasNullRows) {
            throw new RuntimeException(
                'Cannot safely restore NOT NULL on finished_product_material_id: null rows already exist.'
            );
        }

        DB::statement(
            'ALTER TABLE `finished_product_computed_prices` ' .
            'MODIFY `finished_product_material_id` BIGINT UNSIGNED NOT NULL'
        );
    }
};
