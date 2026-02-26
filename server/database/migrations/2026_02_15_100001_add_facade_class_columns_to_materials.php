<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Добавляет структурные колонки фасадов в materials для нормальной фильтрации/индексации.
 *
 * facade_class — нормализованный "класс изделия" (STANDARD/PREMIUM/GEOMETRY/RADIUS/...)
 * facade_base_type, facade_thickness_mm, facade_covering, facade_cover_type — identity-поля для strict-подбора
 * facade_collection, facade_price_group_label, facade_decor_label, facade_article_optional — справочные поля
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            // Identity fields for strict matching
            $table->string('facade_class', 32)->nullable()->after('metadata')
                ->comment('MVP: STANDARD|PREMIUM|GEOMETRY|RADIUS|VITRINA|RESHETKA|AKRIL|ALUMINIUM|MASSIV|ECONOMY');
            $table->string('facade_base_type', 50)->nullable()->after('facade_class')
                ->comment('Base material: mdf, dsp, mdf_aglo, fanera, massiv');
            $table->smallInteger('facade_thickness_mm')->unsigned()->nullable()->after('facade_base_type');
            $table->string('facade_covering', 50)->nullable()->after('facade_thickness_mm')
                ->comment('Covering type code: pvc_film, plastic, enamel, veneer, solid_wood, aluminum_frame, other');
            $table->string('facade_cover_type', 50)->nullable()->after('facade_covering')
                ->comment('Cover variant: matte, gloss, metallic, soft_touch, textured');

            // Reference/informational fields
            $table->string('facade_collection', 100)->nullable()->after('facade_cover_type');
            $table->string('facade_price_group_label', 50)->nullable()->after('facade_collection')
                ->comment('Price group label from supplier, informational only');
            $table->string('facade_decor_label', 255)->nullable()->after('facade_price_group_label')
                ->comment('Decor description, informational only');
            $table->string('facade_article_optional', 255)->nullable()->after('facade_decor_label')
                ->comment('Alternative article if different from materials.article');

            // Composite index for strict matching (identity)
            $table->index(
                ['type', 'facade_base_type', 'facade_thickness_mm', 'facade_covering', 'facade_cover_type', 'facade_class'],
                'materials_facade_strict_match_idx'
            );
        });

        // Migrate existing facade materials: populate new columns from metadata JSON
        DB::statement("
            UPDATE materials
            SET
                facade_base_type = COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.base.material')),
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.base_material')),
                    'mdf'
                ),
                facade_thickness_mm = COALESCE(
                    CAST(JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.thickness_mm')) AS UNSIGNED),
                    thickness_mm
                ),
                facade_covering = COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.finish.type')),
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.finish_type'))
                ),
                facade_cover_type = JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.finish.variant')),
                facade_collection = COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.collection')),
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.finish.collection'))
                ),
                facade_price_group_label = JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.price_group')),
                facade_decor_label = COALESCE(
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.decor')),
                    JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.finish.name'))
                ),
                facade_class = 'STANDARD'
            WHERE type = 'facade'
              AND facade_base_type IS NULL
        ");
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex('materials_facade_strict_match_idx');
            $table->dropColumn([
                'facade_class',
                'facade_base_type',
                'facade_thickness_mm',
                'facade_covering',
                'facade_cover_type',
                'facade_collection',
                'facade_price_group_label',
                'facade_decor_label',
                'facade_article_optional',
            ]);
        });
    }
};
