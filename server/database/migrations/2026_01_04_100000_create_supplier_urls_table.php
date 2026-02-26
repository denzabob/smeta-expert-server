<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Создаёт таблицу для хранения собранных URL товаров от поставщиков.
     * Позволяет разделить сбор URL от парсинга материалов.
     */
    public function up(): void
    {
        Schema::create('supplier_urls', function (Blueprint $table) {
            $table->id();
            
            // Поставщик и тип материала
            $table->string('supplier_name')->index();
            $table->string('material_type')->nullable()->index(); // 'лдсп', 'мдф', 'хдф', и т.д.
            
            // URL и валидация
            $table->text('url');
            $table->boolean('is_valid')->default(true)->index();
            
            // Метаданные сбора
            $table->timestamp('collected_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->integer('retries')->default(0);
            $table->text('validation_error')->nullable(); // Ошибка валидации (если есть)
            
            // Timestamps
            $table->timestamps();
            
            // Составной индекс для быстрого поиска
            $table->index(['supplier_name', 'material_type', 'is_valid']);
            
            // Уникальность: один URL для поставщика (избегаем дубликатов)
            $table->unique(['supplier_name', 'url'], 'supplier_url_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_urls');
    }
};
