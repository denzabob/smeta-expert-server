<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('finished_product_price_sources', function (Blueprint $table) {
            if (!Schema::hasColumn('finished_product_price_sources', 'finished_product_specification_id')) {
                $table->unsignedBigInteger('finished_product_specification_id')
                    ->nullable()
                    ->after('id');
                $table->index(
                    ['finished_product_specification_id', 'status'],
                    'fp_price_sources_spec_status_idx'
                );
                $table->foreign('finished_product_specification_id', 'fp_price_sources_spec_fk')
                    ->references('id')
                    ->on('finished_product_specifications')
                    ->cascadeOnDelete();
            }
        });

        Schema::table('finished_product_aggregation_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('finished_product_aggregation_profiles', 'finished_product_specification_id')) {
                $table->unsignedBigInteger('finished_product_specification_id')
                    ->nullable()
                    ->after('id');
                $table->unique(
                    'finished_product_specification_id',
                    'fp_aggregation_profiles_spec_unique'
                );
                $table->foreign('finished_product_specification_id', 'fp_aggregation_profiles_spec_fk')
                    ->references('id')
                    ->on('finished_product_specifications')
                    ->cascadeOnDelete();
            }
        });

        Schema::table('finished_product_computed_prices', function (Blueprint $table) {
            if (!Schema::hasColumn('finished_product_computed_prices', 'finished_product_specification_id')) {
                $table->unsignedBigInteger('finished_product_specification_id')
                    ->nullable()
                    ->after('id');
                $table->unique(
                    'finished_product_specification_id',
                    'fp_computed_prices_spec_unique'
                );
                $table->foreign('finished_product_specification_id', 'fp_computed_prices_spec_fk')
                    ->references('id')
                    ->on('finished_product_specifications')
                    ->cascadeOnDelete();
            }
        });

        $materials = DB::table('materials')
            ->where('type', 'facade')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->get([
                'id',
                'user_id',
                'name',
                'article',
                'is_active',
                'facade_class',
                'facade_base_type',
                'facade_thickness_mm',
                'facade_covering',
                'facade_cover_type',
                'facade_collection',
                'facade_decor_label',
                'facade_price_group_label',
                'metadata',
                'created_at',
                'updated_at',
            ]);

        foreach ($materials as $material) {
            $specId = DB::table('finished_product_specifications')->insertGetId([
                'user_id' => $material->user_id,
                'product_type' => 'facade',
                'name' => $material->name,
                'article' => $material->article,
                'is_active' => (bool) $material->is_active,
                'facade_class' => $material->facade_class,
                'base_type' => $material->facade_base_type,
                'thickness_mm' => $material->facade_thickness_mm,
                'covering' => $material->facade_covering,
                'cover_type' => $material->facade_cover_type,
                'collection' => $material->facade_collection,
                'decor_label' => $material->facade_decor_label,
                'price_group_label' => $material->facade_price_group_label,
                'metadata' => $material->metadata,
                'created_at' => $material->created_at ?? now(),
                'updated_at' => $material->updated_at ?? now(),
            ]);

            DB::table('finished_product_price_sources')
                ->where('finished_product_material_id', $material->id)
                ->whereNull('finished_product_specification_id')
                ->update(['finished_product_specification_id' => $specId]);

            DB::table('finished_product_aggregation_profiles')
                ->where('finished_product_material_id', $material->id)
                ->whereNull('finished_product_specification_id')
                ->update(['finished_product_specification_id' => $specId]);

            DB::table('finished_product_computed_prices')
                ->where('finished_product_material_id', $material->id)
                ->whereNull('finished_product_specification_id')
                ->update(['finished_product_specification_id' => $specId]);
        }
    }

    public function down(): void
    {
        Schema::table('finished_product_computed_prices', function (Blueprint $table) {
            if (Schema::hasColumn('finished_product_computed_prices', 'finished_product_specification_id')) {
                $table->dropForeign('fp_computed_prices_spec_fk');
                $table->dropUnique('fp_computed_prices_spec_unique');
                $table->dropColumn('finished_product_specification_id');
            }
        });

        Schema::table('finished_product_aggregation_profiles', function (Blueprint $table) {
            if (Schema::hasColumn('finished_product_aggregation_profiles', 'finished_product_specification_id')) {
                $table->dropForeign('fp_aggregation_profiles_spec_fk');
                $table->dropUnique('fp_aggregation_profiles_spec_unique');
                $table->dropColumn('finished_product_specification_id');
            }
        });

        Schema::table('finished_product_price_sources', function (Blueprint $table) {
            if (Schema::hasColumn('finished_product_price_sources', 'finished_product_specification_id')) {
                $table->dropForeign('fp_price_sources_spec_fk');
                $table->dropIndex('fp_price_sources_spec_status_idx');
                $table->dropColumn('finished_product_specification_id');
            }
        });
    }
};
