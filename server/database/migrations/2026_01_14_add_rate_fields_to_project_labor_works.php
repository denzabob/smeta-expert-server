<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляет поля для связи ставок с работами в проекте:
     * - project_profile_rate_id: связь на project_profile_rates
     * - rate_per_hour: снимок ставки на момент назначения
     * - cost_total: итоговая стоимость работы (hours × rate_per_hour)
     * - rate_snapshot: JSON с полной информацией об источниках и обосновании ставки
     */
    public function up(): void
    {
        Schema::table('project_labor_works', function (Blueprint $table) {
            // Связь на project_profile_rates
            $table->unsignedBigInteger('project_profile_rate_id')->nullable()->after('position_profile_id');
            $table->foreign('project_profile_rate_id')
                ->references('id')
                ->on('project_profile_rates')
                ->onDelete('set null');

            // Снимок ставки руб/час на момент назначения
            $table->decimal('rate_per_hour', 10, 2)->nullable()->after('project_profile_rate_id');

            // Итоговая стоимость работы (hours × rate_per_hour)
            $table->decimal('cost_total', 12, 2)->nullable()->after('rate_per_hour');

            // JSON снимок источников/обоснования ставки
            $table->longText('rate_snapshot')->nullable()->after('cost_total');

            // Индексы для быстрого поиска
            $table->index(['project_id', 'position_profile_id']);
            $table->index('project_profile_rate_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_labor_works', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'position_profile_id']);
            $table->dropIndex('project_labor_works_project_profile_rate_id_index');
            $table->dropForeign(['project_profile_rate_id']);
            $table->dropColumn([
                'project_profile_rate_id',
                'rate_per_hour',
                'cost_total',
                'rate_snapshot',
            ]);
        });
    }
};
