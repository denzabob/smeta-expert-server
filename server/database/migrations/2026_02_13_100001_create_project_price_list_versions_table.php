<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Minimal evidence layer — links projects to the price_list_versions
 * actually used in cost calculations. Each price_list_version already stores:
 *   sha256, source_type, source_url, file_path, original_filename, captured_at
 *
 * This pivot lets us answer "which price-lists back this project?" for:
 *   - PDF report "Источники ценовых данных" section
 *   - Verification portal sources listing
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_price_list_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('price_list_version_id');
            $table->string('role', 50)->default('material_price')
                  ->comment('material_price | operation_price | facade_price');
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamps();

            $table->unique(['project_id', 'price_list_version_id', 'role'], 'pplv_project_version_role_unique');

            $table->foreign('project_id')
                  ->references('id')->on('projects')
                  ->onDelete('cascade');

            $table->foreign('price_list_version_id')
                  ->references('id')->on('price_list_versions')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_price_list_versions');
    }
};
