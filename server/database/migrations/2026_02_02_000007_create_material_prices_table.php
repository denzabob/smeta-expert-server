<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('material_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_version_id')->constrained()->onDelete('cascade');
            $table->foreignId('material_id')->constrained()->onDelete('cascade');
            
            // Source data (для аудита)
            $table->decimal('source_price', 18, 4)->comment('Оригинальная цена от поставщика');
            $table->string('source_unit', 50)->nullable()->comment('Единица в прайсе');
            
            // Conversion
            $table->decimal('conversion_factor', 18, 6)->default(1.0);
            
            // Computed price
            $table->decimal('price_per_internal_unit', 18, 4)
                ->comment('= source_price / conversion_factor');
            
            // Currency
            $table->string('currency', 3)->default('RUB');
            
            // Row reference
            $table->unsignedInteger('source_row_index')->nullable();
            
            // Optional fields from price list
            $table->string('article')->nullable();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->decimal('thickness', 8, 2)->nullable();
            
            $table->timestamps();

            $table->unique(['price_list_version_id', 'material_id']);
            $table->index(['material_id', 'price_list_version_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('material_prices');
    }
};
