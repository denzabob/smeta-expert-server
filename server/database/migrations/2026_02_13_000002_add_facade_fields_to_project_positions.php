<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Расширение project_positions для поддержки фасадов:
 * - kind: panel (default) | facade
 * - supplier_price_item_id: FK → material_prices (цена из прайса)
 * - decor_label: человекочитаемое описание декора
 * - thickness_mm: толщина фасада
 * - base_material_label: основа (МДФ и т.п.)
 * - finish_type: тип покрытия (pvc_film, plastic, enamel, veneer, solid_wood, aluminum_frame, other)
 * - finish_name: конкретный декор/плёнка/код
 * - facade_material_id: ссылка на materials(type=facade)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            $table->string('kind', 20)->default('panel')->after('project_id');
            $table->unsignedBigInteger('facade_material_id')->nullable()->after('material_id');
            $table->unsignedBigInteger('supplier_price_item_id')->nullable()->after('facade_material_id');
            $table->string('decor_label', 255)->nullable()->after('supplier_price_item_id');
            $table->unsignedSmallInteger('thickness_mm')->nullable()->after('decor_label');
            $table->string('base_material_label', 100)->nullable()->after('thickness_mm');
            $table->string('finish_type', 50)->nullable()->after('base_material_label');
            $table->string('finish_name', 255)->nullable()->after('finish_type');
            $table->decimal('price_per_m2', 18, 4)->nullable()->after('finish_name');
            $table->decimal('area_m2', 12, 6)->nullable()->after('price_per_m2');
            $table->decimal('total_price', 18, 4)->nullable()->after('area_m2');

            $table->foreign('facade_material_id')
                  ->references('id')->on('materials')
                  ->onDelete('set null');

            $table->foreign('supplier_price_item_id')
                  ->references('id')->on('material_prices')
                  ->onDelete('set null');

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::table('project_positions', function (Blueprint $table) {
            $table->dropForeign(['facade_material_id']);
            $table->dropForeign(['supplier_price_item_id']);
            $table->dropIndex(['kind']);

            $table->dropColumn([
                'kind',
                'facade_material_id',
                'supplier_price_item_id',
                'decor_label',
                'thickness_mm',
                'base_material_label',
                'finish_type',
                'finish_name',
                'price_per_m2',
                'area_m2',
                'total_price',
            ]);
        });
    }
};
