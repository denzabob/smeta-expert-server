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
        Schema::table('parsing_sessions', function (Blueprint $table) {
            // Добавляем поле total_urls для отслеживания общего количества собранных URL
            $table->integer('total_urls')->default(0)->after('errors_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('parsing_sessions', function (Blueprint $table) {
            $table->dropColumn('total_urls');
        });
    }
};
