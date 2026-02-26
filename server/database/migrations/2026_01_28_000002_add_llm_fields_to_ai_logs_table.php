<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Добавляем поля для расширенного логирования LLM запросов:
     * - provider_name: имя провайдера (openrouter, deepseek, etc.)
     * - fallback_used: был ли использован fallover
     * - failover_chain: цепочка попыток (например: ["openrouter:timeout","deepseek:ok"])
     * - error_type: тип ошибки (timeout|http_429|http_5xx|invalid_json|auth|unknown)
     * - http_status: HTTP статус ответа (если применимо)
     */
    public function up(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->string('provider_name', 50)->nullable()->after('model_name');
            $table->boolean('fallback_used')->default(false)->after('provider_name');
            $table->jsonb('failover_chain')->nullable()->after('fallback_used');
            $table->string('error_type', 30)->nullable()->after('error_message');
            $table->unsignedSmallInteger('http_status')->nullable()->after('error_type');

            // Индексы для аналитики
            $table->index(['provider_name', 'created_at']);
            $table->index(['error_type', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_logs', function (Blueprint $table) {
            $table->dropIndex(['provider_name', 'created_at']);
            $table->dropIndex(['error_type', 'created_at']);

            $table->dropColumn([
                'provider_name',
                'fallback_used',
                'failover_chain',
                'error_type',
                'http_status',
            ]);
        });
    }
};
