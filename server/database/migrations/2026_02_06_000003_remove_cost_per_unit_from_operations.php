<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Удаляем cost_per_unit из таблицы operations.
     * Базовые операции не должны иметь цен - цены хранятся в operation_prices
     * с привязкой к поставщикам.
     */
    public function up(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            if (Schema::hasColumn('operations', 'cost_per_unit')) {
                $table->dropColumn('cost_per_unit');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->decimal('cost_per_unit', 10, 2)->default(0)->after('unit');
        });
    }
};
