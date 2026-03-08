<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('material_price_histories', function (Blueprint $table) {
            if (!Schema::hasColumn('material_price_histories', 'true_score')) {
                $table->unsignedSmallInteger('true_score')->default(100)->after('is_verified');
            }
            if (!Schema::hasColumn('material_price_histories', 'raw_source_url')) {
                $table->text('raw_source_url')->nullable()->after('source_url');
            }
            if (!Schema::hasColumn('material_price_histories', 'normalized_source_url')) {
                $table->string('normalized_source_url', 2048)->nullable()->after('raw_source_url');
            }
        });

        // MySQL utf8mb4: use prefix index to stay under key length limits
        $indexExists = collect(DB::select("SHOW INDEX FROM material_price_histories WHERE Key_name = 'mph_norm_price_region_idx'"))->isNotEmpty();
        if (!$indexExists) {
            DB::statement(
                'CREATE INDEX mph_norm_price_region_idx ON material_price_histories (normalized_source_url(191), price_per_unit, region_id)'
            );
        }
    }

    public function down(): void
    {
        Schema::table('material_price_histories', function (Blueprint $table) {
            if (Schema::hasColumn('material_price_histories', 'normalized_source_url')) {
                $table->dropColumn('normalized_source_url');
            }
            if (Schema::hasColumn('material_price_histories', 'raw_source_url')) {
                $table->dropColumn('raw_source_url');
            }
            if (Schema::hasColumn('material_price_histories', 'true_score')) {
                $table->dropColumn('true_score');
            }
        });
        try {
            DB::statement('DROP INDEX mph_norm_price_region_idx ON material_price_histories');
        } catch (\Throwable $e) {
            // ignore missing index during rollback
        }
    }
};
