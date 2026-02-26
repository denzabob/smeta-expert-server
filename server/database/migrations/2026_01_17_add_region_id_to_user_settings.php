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
        Schema::table('user_settings', function (Blueprint $table) {
            // Добавить region_id для хранения дефолтного региона пользователя
            $table->unsignedBigInteger('region_id')->nullable()->after('user_id');
            $table->index('region_id');
            $table->foreign('region_id')->references('id')->on('regions')->nullOnDelete();
        });

        // Нормализовать waste_coefficient default на 1.0 ("без изменения")
        Schema::table('user_settings', function (Blueprint $table) {
            $table->double('waste_coefficient')->default(1.0)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_settings', function (Blueprint $table) {
            $table->dropForeign(['region_id']);
            $table->dropIndex(['region_id']);
            $table->dropColumn('region_id');
        });

        Schema::table('user_settings', function (Blueprint $table) {
            $table->double('waste_coefficient')->default(1.2)->change();
        });
    }
};
