<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Инициализировать hours_source для существующих записей
        // которые ещё не имеют значения (NULL)
        DB::table('project_labor_works')
            ->whereNull('hours_source')
            ->update([
                'hours_source' => 'manual',
                'hours_manual' => DB::raw('hours')
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Не восстанавливаем, так как это инициализация существующих данных
    }
};
