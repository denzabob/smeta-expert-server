<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * operation_id в operation_prices теперь nullable:
     * позиции прайс-листа сохраняются даже без привязки к базовой операции.
     */
    public function up(): void
    {
        // 1) Add plain index on price_list_version_id so MySQL has a backing
        //    index for the FK after we drop the unique composite index
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->index('price_list_version_id', 'op_prices_version_idx');
        });

        // 2) Drop unique index (version_id, operation_id)
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->dropUnique('operation_prices_price_list_version_id_operation_id_unique');
        });

        // 3) Drop composite index on (operation_id, price_list_version_id)
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->dropIndex('operation_prices_operation_id_price_list_version_id_index');
        });

        // 4) Make operation_id nullable
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('operation_id')->nullable()->change();
        });

        // 5) Re-add index on operation_id (now nullable)
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->index(['operation_id', 'price_list_version_id'], 'op_prices_op_version_idx');
        });

        // 6) Add foreign key on operation_id with SET NULL on delete
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->foreign('operation_id')
                ->references('id')
                ->on('operations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Remove unlinked rows first (operation_id IS NULL)
        DB::table('operation_prices')->whereNull('operation_id')->delete();

        // Drop FK
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->dropForeign(['operation_id']);
        });

        // Drop index we added
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->dropIndex('op_prices_op_version_idx');
        });

        // Make NOT NULL again
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('operation_id')->nullable(false)->change();
        });

        // Re-add original indexes
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->unique(['price_list_version_id', 'operation_id'], 'operation_prices_price_list_version_id_operation_id_unique');
            $table->index(['operation_id', 'price_list_version_id'], 'operation_prices_operation_id_price_list_version_id_index');
        });

        // Drop the temporary index we added
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->dropIndex('op_prices_version_idx');
        });
    }
};
