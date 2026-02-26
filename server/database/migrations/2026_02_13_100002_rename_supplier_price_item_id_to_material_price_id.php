<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Rename supplier_price_item_id → material_price_id in project_positions.
 * Semantically correct: the FK points to material_prices.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MariaDB / MySQL rename column with foreign key
        Schema::table('project_positions', function (Blueprint $table) {
            // Drop the existing foreign key first
            $table->dropForeign(['supplier_price_item_id']);
        });

        Schema::table('project_positions', function (Blueprint $table) {
            $table->renameColumn('supplier_price_item_id', 'material_price_id');
        });

        Schema::table('project_positions', function (Blueprint $table) {
            $table->foreign('material_price_id')
                  ->references('id')->on('material_prices')
                  ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            $table->dropForeign(['material_price_id']);
        });

        Schema::table('project_positions', function (Blueprint $table) {
            $table->renameColumn('material_price_id', 'supplier_price_item_id');
        });

        Schema::table('project_positions', function (Blueprint $table) {
            $table->foreign('supplier_price_item_id')
                  ->references('id')->on('material_prices')
                  ->onDelete('set null');
        });
    }
};
