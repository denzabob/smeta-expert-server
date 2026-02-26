<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * supplier_operations - операции ровно так, как они называются у поставщика.
     * Это словарь поставщика, без унификации.
     */
    public function up(): void
    {
        Schema::create('supplier_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade');
            $table->string('name');
            $table->string('unit')->nullable()->comment('Единица измерения как в прайсе поставщика');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('external_key')->nullable()->comment('SKU/article или hash от name');
            $table->string('search_name')->nullable()->comment('Нормализованное имя для поиска');
            $table->enum('origin', ['import', 'manual'])->default('import');
            $table->timestamps();
            
            // Индексы
            $table->index(['supplier_id', 'name']);
            $table->index(['supplier_id', 'external_key']);
            $table->index('search_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_operations');
    }
};
