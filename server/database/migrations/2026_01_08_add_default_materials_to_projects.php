<?php

use App\Models\Material;
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
        Schema::table('projects', function (Blueprint $table) {
            // Материалы по умолчанию при добавлении позиций
            $table->foreignId('default_plate_material_id')->nullable()->constrained('materials')->nullOnDelete()->comment('Плитный материал по умолчанию');
            $table->foreignId('default_edge_material_id')->nullable()->constrained('materials')->nullOnDelete()->comment('Кромочный материал по умолчанию');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Сначала удаляем иностранные ключи
            $table->dropForeign(['default_plate_material_id']);
            $table->dropForeign(['default_edge_material_id']);
            // Потом удаляем колонки
            $table->dropColumn(['default_plate_material_id', 'default_edge_material_id']);
        });
    }
};
