<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finished_product_computed_prices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('finished_product_material_id');
            $table->decimal('computed_price_per_m2', 18, 4)->nullable();
            $table->string('method', 30)->nullable();
            $table->unsignedInteger('source_count')->default(0);
            $table->decimal('min_price', 18, 4)->nullable();
            $table->decimal('max_price', 18, 4)->nullable();
            $table->dateTime('computed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('finished_product_material_id', 'fp_computed_prices_material_unique');
            $table->foreign('finished_product_material_id', 'fp_computed_prices_material_fk')
                ->references('id')
                ->on('materials')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_product_computed_prices');
    }
};
