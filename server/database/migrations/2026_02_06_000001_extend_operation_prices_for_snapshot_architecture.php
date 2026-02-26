<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Расширение operation_prices для архитектуры snapshot-prices
 * 
 * Ключевые изменения:
 * 1. supplier_id - обязательная привязка к поставщику
 * 2. price_type - розница/опт
 * 3. source_name - название из прайса поставщика (для аудита)
 * 4. external_key - артикул/SKU поставщика
 * 5. match_confidence - уверенность сопоставления (alias/exact/fuzzy/manual)
 * 6. meta - служебные данные JSON
 * 
 * ВАЖНО: operations.cost_per_unit больше НЕ используется в расчётах.
 * Все цены берутся из operation_prices (активная версия/медиана).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operation_prices', function (Blueprint $table) {
            // Поставщик обязателен для архитектуры snapshot-prices
            // nullable на первом этапе для обратной совместимости, потом сделаем required
            $table->foreignId('supplier_id')
                ->nullable()
                ->after('id')
                ->constrained('suppliers')
                ->onDelete('cascade');
            
            // Тип цены (розница/опт)
            $table->string('price_type', 20)
                ->default('retail')
                ->after('currency')
                ->comment('retail или wholesale');
            
            // Данные из прайса поставщика для аудита и matching
            $table->string('source_name', 500)
                ->nullable()
                ->after('source_row_index')
                ->comment('Название как в прайсе поставщика');
            
            $table->string('external_key', 255)
                ->nullable()
                ->after('source_name')
                ->comment('SKU/артикул поставщика');
            
            // Уверенность сопоставления с базовой операцией
            $table->string('match_confidence', 20)
                ->nullable()
                ->after('external_key')
                ->comment('alias, exact, fuzzy, manual');
            
            // Служебные данные (исходная строка, комментарии, признаки)
            $table->json('meta')
                ->nullable()
                ->after('match_confidence');
        });

        // Добавляем индексы для производительности
        Schema::table('operation_prices', function (Blueprint $table) {
            // Основной индекс для поиска цен по поставщику и версии
            $table->index(['supplier_id', 'price_list_version_id'], 'op_supplier_version_idx');
            
            // Индекс для поиска по артикулу
            $table->index('external_key', 'op_external_key_idx');
            
            // Индекс для поиска по source_name (для matching)
            $table->index('source_name', 'op_source_name_idx');
        });

        // Обновляем unique constraint для поддержки нескольких типов цен
        // Сначала удаляем старый
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->dropUnique(['price_list_version_id', 'operation_id']);
        });

        // Создаём новый с учётом supplier_id и price_type
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->unique(
                ['supplier_id', 'price_list_version_id', 'operation_id', 'price_type'],
                'op_supplier_version_operation_type_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('operation_prices', function (Blueprint $table) {
            // Удаляем новый unique constraint
            $table->dropUnique('op_supplier_version_operation_type_unique');
        });

        // Восстанавливаем старый unique constraint
        Schema::table('operation_prices', function (Blueprint $table) {
            $table->unique(['price_list_version_id', 'operation_id']);
        });

        Schema::table('operation_prices', function (Blueprint $table) {
            // Удаляем индексы
            $table->dropIndex('op_supplier_version_idx');
            $table->dropIndex('op_external_key_idx');
            $table->dropIndex('op_source_name_idx');
            
            // Удаляем колонки
            $table->dropForeign(['supplier_id']);
            $table->dropColumn([
                'supplier_id',
                'price_type',
                'source_name',
                'external_key',
                'match_confidence',
                'meta',
            ]);
        });
    }
};
