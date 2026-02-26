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
            // Описание для коэффициента плитных материалов
            $table->json('waste_plate_description')->nullable()->after('waste_plate_coefficient');
            $table->boolean('show_waste_plate_description')->default(false)->after('waste_plate_description');

            // Описание для коэффициента кромки
            $table->json('waste_edge_description')->nullable()->after('waste_edge_coefficient');
            $table->boolean('show_waste_edge_description')->default(false)->after('waste_edge_description');

            // Описание для коэффициента операций
            $table->json('waste_operations_description')->nullable()->after('waste_operations_coefficient');
            $table->boolean('show_waste_operations_description')->default(false)->after('waste_operations_description');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'waste_plate_description',
                'show_waste_plate_description',
                'waste_edge_description',
                'show_waste_edge_description',
                'waste_operations_description',
                'show_waste_operations_description',
            ]);
        });
    }
};
