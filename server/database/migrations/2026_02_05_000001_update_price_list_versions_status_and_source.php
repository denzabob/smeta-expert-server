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
        Schema::table('price_list_versions', function (Blueprint $table) {
            // Обновляем статусы: draft → inactive, добавляем source_type
            $table->enum('source_type', ['file', 'manual', 'url'])->default('file')->after('status');
            $table->string('source_url')->nullable()->after('source_type');
            $table->string('manual_label')->nullable()->after('source_url')->comment('Название для ручного ввода');
            $table->unsignedBigInteger('size_bytes')->nullable()->after('sha256');
        });

        // Меняем enum для status: draft → inactive
        DB::statement("ALTER TABLE price_list_versions MODIFY COLUMN status ENUM('inactive', 'active', 'archived') DEFAULT 'inactive'");
        
        // Обновляем существующие draft → inactive
        DB::table('price_list_versions')
            ->where('status', 'draft')
            ->update(['status' => 'inactive']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE price_list_versions MODIFY COLUMN status ENUM('draft', 'active', 'archived') DEFAULT 'draft'");
        
        Schema::table('price_list_versions', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source_url', 'manual_label', 'size_bytes']);
        });
    }
};
