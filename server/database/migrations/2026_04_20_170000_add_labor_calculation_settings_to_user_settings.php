<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->decimal('labor_employer_insurance_rate', 5, 4)
                ->default(0.3000)
                ->after('show_waste_operations_description');
            $table->integer('labor_load_factor_calendar_hours')
                ->default(160)
                ->after('labor_employer_insurance_rate');
            $table->integer('labor_load_factor_productive_hours')
                ->default(120)
                ->after('labor_load_factor_calendar_hours');
            $table->decimal('labor_planned_profitability_rate', 5, 4)
                ->default(0.1500)
                ->after('labor_load_factor_productive_hours');
            $table->string('labor_aggregation_strategy', 20)
                ->default('auto')
                ->after('labor_planned_profitability_rate');
            $table->integer('labor_rate_rounding_scale')
                ->default(2)
                ->after('labor_aggregation_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropColumn([
                'labor_employer_insurance_rate',
                'labor_load_factor_calendar_hours',
                'labor_load_factor_productive_hours',
                'labor_planned_profitability_rate',
                'labor_aggregation_strategy',
                'labor_rate_rounding_scale',
            ]);
        });
    }
};
