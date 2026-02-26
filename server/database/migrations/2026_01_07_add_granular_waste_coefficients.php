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
        Schema::table('projects', function (Blueprint $table) {
            // Коэффициенты отходов по типам
            $table->decimal('waste_plate_coefficient', 5, 2)->nullable()->comment('Коэффициент отходов для плитных материалов');
            $table->decimal('waste_edge_coefficient', 5, 2)->nullable()->comment('Коэффициент отходов для кромки');
            $table->decimal('waste_operations_coefficient', 5, 2)->nullable()->comment('Коэффициент отходов для операций');
            
            // Флаги применения коэффициентов
            $table->boolean('apply_waste_to_plate')->default(true)->comment('Применять коэффициент отходов к плитным материалам');
            $table->boolean('apply_waste_to_edge')->default(true)->comment('Применять коэффициент отходов к кромке');
            $table->boolean('apply_waste_to_operations')->default(false)->comment('Применять коэффициент отходов к операциям');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'waste_plate_coefficient',
                'waste_edge_coefficient',
                'waste_operations_coefficient',
                'apply_waste_to_plate',
                'apply_waste_to_edge',
                'apply_waste_to_operations',
            ]);
        });
    }
};
