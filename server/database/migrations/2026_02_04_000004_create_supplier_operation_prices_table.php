<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * supplier_operation_prices - цены операций по версии прайса и поставщику.
     * Импорт создаёт записи только здесь, не в operations.cost_per_unit.
     */
    public function up(): void
    {
        Schema::create('supplier_operation_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_operation_id')->constrained('supplier_operations')->onDelete('cascade');
            $table->foreignId('price_list_version_id')->constrained('price_list_versions')->onDelete('cascade');
            $table->decimal('price_value', 12, 2);
            $table->string('unit')->nullable()->comment('Единица измерения как в прайсе для аудита');
            $table->enum('price_type', ['retail', 'wholesale'])->default('retail');
            $table->string('currency', 3)->default('RUB');
            $table->unsignedInteger('source_row_index')->nullable()->comment('Номер строки в файле импорта');
            $table->timestamps();
            
            // Уникальность: одна цена на операцию+версию+тип
            $table->unique(
                ['supplier_operation_id', 'price_list_version_id', 'price_type'],
                'supplier_op_price_unique'
            );
            
            // Индексы для поиска
            $table->index('price_list_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_operation_prices');
    }
};
