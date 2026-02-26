<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * "Память соответствий" - связывает внешние данные поставщика с внутренним каталогом
     */
    public function up(): void
    {
        if (Schema::hasTable('supplier_product_aliases')) {
            return;
        }
        
        Schema::create('supplier_product_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->onDelete('cascade');
            
            // External identification
            $table->string('external_key')->comment('SKU/article или стабильный ключ (hash от name)');
            $table->string('external_name')->nullable()->comment('Оригинальное название от поставщика');
            
            // Internal mapping (polymorphic)
            $table->enum('internal_item_type', ['material', 'operation'])->index();
            $table->unsignedBigInteger('internal_item_id');
            
            // Unit conversion
            $table->string('supplier_unit', 50)->nullable()->comment('Единица поставщика: упак., лист, компл.');
            $table->string('internal_unit', 50)->nullable()->comment('Внутренняя единица: шт, м², п.м.');
            $table->decimal('conversion_factor', 18, 6)->default(1.0)
                ->comment('Сколько внутренних единиц в 1 единице поставщика');
            
            // Price transformation rule (для будущего расширения)
            $table->enum('price_transform', ['divide', 'multiply', 'none'])->default('divide')
                ->comment('MVP: всегда divide. Price_internal = Price_supplier / conversion_factor');
            
            // Confidence tracking
            $table->enum('confidence', ['manual', 'auto_exact', 'auto_fuzzy'])->default('manual');
            $table->decimal('similarity_score', 5, 4)->nullable()->comment('Similarity score при auto matching');
            
            // Timestamps для трекинга использования
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            
            $table->timestamps();

            // Уникальность: один внешний ключ = один внутренний товар у поставщика
            $table->unique(['supplier_id', 'external_key', 'internal_item_type'], 'spa_supplier_key_type_unique');
            
            // Индексы для быстрого поиска
            $table->index(['supplier_id', 'internal_item_type', 'internal_item_id'], 'spa_supplier_internal_idx');
            $table->index(['external_key']);
            $table->index(['internal_item_type', 'internal_item_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_product_aliases');
    }
};
