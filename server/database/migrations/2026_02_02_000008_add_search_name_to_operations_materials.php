<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляем нормализованное поле search_name для быстрого fuzzy matching
     */
    public function up(): void
    {
        // Add search_name to operations
        Schema::table('operations', function (Blueprint $table) {
            $table->string('search_name')->nullable()->after('name')
                ->comment('Нормализованное имя для поиска (lowercase, без спецсимволов)');
            $table->index('search_name');
        });

        // Add search_name to materials
        Schema::table('materials', function (Blueprint $table) {
            $table->string('search_name')->nullable()->after('name')
                ->comment('Нормализованное имя для поиска (lowercase, без спецсимволов)');
            $table->index('search_name');
        });

        // Populate search_name for existing records
        DB::statement("UPDATE operations SET search_name = LOWER(REPLACE(REPLACE(REPLACE(name, '\"', ''), '''', ''), ',', ''))");
        DB::statement("UPDATE materials SET search_name = LOWER(REPLACE(REPLACE(REPLACE(name, '\"', ''), '''', ''), ',', ''))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operations', function (Blueprint $table) {
            $table->dropColumn('search_name');
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn('search_name');
        });
    }
};
