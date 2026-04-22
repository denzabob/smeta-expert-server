<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finished_product_price_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('finished_product_material_id');
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('price_list_version_id')->nullable();
            $table->string('source_kind', 50);
            $table->decimal('source_price', 18, 4);
            $table->string('source_unit', 50)->nullable();
            $table->decimal('conversion_factor_to_m2', 18, 6)->nullable();
            $table->decimal('price_per_m2_normalized', 18, 4)->nullable();
            $table->dateTime('captured_at')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('article')->nullable();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('status', 30)->default('active');
            $table->string('stale_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['finished_product_material_id', 'status'], 'fp_price_sources_material_status_idx');
            $table->index(['finished_product_material_id', 'source_kind'], 'fp_price_sources_material_kind_idx');
            $table->foreign('finished_product_material_id', 'fp_price_sources_material_fk')
                ->references('id')
                ->on('materials')
                ->cascadeOnDelete();
            $table->foreign('supplier_id', 'fp_price_sources_supplier_fk')
                ->references('id')
                ->on('suppliers')
                ->nullOnDelete();
            $table->foreign('price_list_version_id', 'fp_price_sources_version_fk')
                ->references('id')
                ->on('price_list_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_product_price_sources');
    }
};
