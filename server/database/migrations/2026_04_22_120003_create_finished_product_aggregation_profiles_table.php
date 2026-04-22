<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finished_product_aggregation_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('finished_product_material_id');
            $table->string('method', 30)->default('median');
            $table->boolean('include_only_active')->default(true);
            $table->boolean('exclude_stale')->default(true);
            $table->unsignedInteger('minimum_sources_count')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('finished_product_material_id', 'fp_aggregation_profiles_material_unique');
            $table->foreign('finished_product_material_id', 'fp_aggregation_profiles_material_fk')
                ->references('id')
                ->on('materials')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finished_product_aggregation_profiles');
    }
};
