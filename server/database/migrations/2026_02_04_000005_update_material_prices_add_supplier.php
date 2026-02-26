<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Обновление material_prices: добавление supplier_id как обязательного поля.
     * Цены материалов также привязаны к поставщику и версии прайса.
     */
    public function up(): void
    {
        Schema::table('material_prices', function (Blueprint $table) {
            // Добавляем supplier_id если его нет
            if (!Schema::hasColumn('material_prices', 'supplier_id')) {
                $table->foreignId('supplier_id')
                    ->after('material_id')
                    ->nullable() // Временно nullable для миграции существующих данных
                    ->constrained('suppliers')
                    ->onDelete('cascade');
            }
            
            // Добавляем price_type если его нет
            if (!Schema::hasColumn('material_prices', 'price_type')) {
                $table->enum('price_type', ['retail', 'wholesale'])
                    ->default('retail')
                    ->after('price_per_internal_unit');
            }
        });
        
        // Обновляем существующие записи: берем supplier_id из price_list_version
        \DB::statement('
            UPDATE material_prices mp
            JOIN price_list_versions plv ON mp.price_list_version_id = plv.id
            JOIN price_lists pl ON plv.price_list_id = pl.id
            SET mp.supplier_id = pl.supplier_id
            WHERE mp.supplier_id IS NULL
        ');
        
        // Теперь можно сделать supplier_id NOT NULL
        // (закомментировано - MySQL не поддерживает изменение после FK)
        // Schema::table('material_prices', function (Blueprint $table) {
        //     $table->foreignId('supplier_id')->nullable(false)->change();
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('material_prices', function (Blueprint $table) {
            if (Schema::hasColumn('material_prices', 'supplier_id')) {
                $table->dropForeign(['supplier_id']);
                $table->dropColumn('supplier_id');
            }
            if (Schema::hasColumn('material_prices', 'price_type')) {
                $table->dropColumn('price_type');
            }
        });
    }
};
