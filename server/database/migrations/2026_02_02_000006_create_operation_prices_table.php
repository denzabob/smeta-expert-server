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
        Schema::create('operation_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_version_id')->constrained()->onDelete('cascade');
            $table->foreignId('operation_id')->constrained()->onDelete('cascade');
            
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
            $table->unsignedInteger('source_row_index')->nullable()->comment('Номер строки в исходном файле');
            
            // Optional fields from price list
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->decimal('min_thickness', 8, 2)->nullable();
            $table->decimal('max_thickness', 8, 2)->nullable();
            $table->string('exclusion_group', 50)->nullable();
            
            $table->timestamps();

            $table->unique(['price_list_version_id', 'operation_id']);
            $table->index(['operation_id', 'price_list_version_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operation_prices');
    }
};
