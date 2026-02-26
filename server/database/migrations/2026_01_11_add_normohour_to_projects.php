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
            // Нормо-час монтажно-сборочных работ
            $table->decimal('normohour_rate', 10, 2)->nullable()->after('show_waste_operations_description')
                ->comment('Ставка, руб/час');
            $table->string('normohour_region', 255)->nullable()->after('normohour_rate')
                ->comment('Город/регион');
            $table->date('normohour_date')->nullable()->after('normohour_region')
                ->comment('Дата актуальности ставки');
            $table->enum('normohour_method', [
                'market_vacancies',
                'commercial_proposals',
                'contractor_estimate',
                'other'
            ])->nullable()->after('normohour_date')
                ->comment('Метод определения (рыночный/КП/договор/иное)');
            $table->longText('normohour_justification')->nullable()->after('normohour_method')
                ->comment('Текст обоснования ставки');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'normohour_rate',
                'normohour_region',
                'normohour_date',
                'normohour_method',
                'normohour_justification',
            ]);
        });
    }
};
