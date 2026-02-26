<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавить поля модели формирования ставки в position_profiles
     * 
     * rate_model: labor (текущая модель) | contractor (подрядная модель)
     * employer_contrib_pct: страховые начисления работодателя (%)
     * base_hours_month: рабочих часов в месяце
     * billable_hours_month: оплачиваемых/продаваемых часов в месяце
     * profit_pct: рентабельность подрядчика (%)
     * rounding_mode: округление ставки (none|int|10|100)
     */
    public function up(): void
    {
        Schema::table('position_profiles', function (Blueprint $table) {
            $table->string('rate_model', 20)->default('labor')->after('sort_order')
                ->comment('Модель формирования ставки: labor | contractor');
            $table->decimal('employer_contrib_pct', 5, 2)->default(30.00)->after('rate_model')
                ->comment('Страховые начисления работодателя, %');
            $table->integer('base_hours_month')->default(160)->after('employer_contrib_pct')
                ->comment('Рабочих часов в месяце');
            $table->integer('billable_hours_month')->default(120)->after('base_hours_month')
                ->comment('Оплачиваемых/продаваемых часов в месяце');
            $table->decimal('profit_pct', 5, 2)->default(15.00)->after('billable_hours_month')
                ->comment('Рентабельность подрядчика, %');
            $table->string('rounding_mode', 10)->default('none')->after('profit_pct')
                ->comment('Округление ставки: none | int | 10 | 100');
        });
    }

    public function down(): void
    {
        Schema::table('position_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'rate_model',
                'employer_contrib_pct',
                'base_hours_month',
                'billable_hours_month',
                'profit_pct',
                'rounding_mode',
            ]);
        });
    }
};
