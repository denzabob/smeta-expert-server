<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_material_library', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('material_id');
            $table->tinyInteger('pinned')->default(0);
            $table->unsignedBigInteger('preferred_region_id')->nullable();
            $table->string('preferred_price_source_url', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Unique constraint: one library entry per user+material
            $table->unique(['user_id', 'material_id'], 'uml_user_material_unique');

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('material_id')->references('id')->on('materials')->onDelete('cascade');
            $table->foreign('preferred_region_id')->references('id')->on('regions')->onDelete('set null');

            // Index for user library queries
            $table->index(['user_id', 'pinned'], 'uml_user_pinned_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_material_library');
    }
};
