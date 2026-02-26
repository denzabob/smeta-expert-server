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
        Schema::table('materials', function (Blueprint $table) {
            // Добавляем размеры листа для нормализации материалов
            $table->integer('length_mm')->nullable()->after('unit')->comment('Длина листа в мм');
            $table->integer('width_mm')->nullable()->after('length_mm')->comment('Ширина листа в мм');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn(['length_mm', 'width_mm']);
        });
    }
};
