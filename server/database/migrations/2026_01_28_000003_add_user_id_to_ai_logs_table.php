<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Добавляем user_id для отслеживания активности пользователей
     */
    public function up(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            
            // Индексы для аналитики по пользователям
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'provider_name', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['user_id', 'provider_name', 'created_at']);
            $table->dropColumn('user_id');
        });
    }
};
