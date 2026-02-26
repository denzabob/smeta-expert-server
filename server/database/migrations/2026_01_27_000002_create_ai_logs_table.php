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
        Schema::create('ai_logs', function (Blueprint $table) {
            $table->id();
            
            // Хеш входных данных для дедупликации
            $table->char('input_hash', 32)->index();
            
            // Название использованной модели
            $table->string('model_name', 100);
            
            // Токены (если доступны)
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            
            // Стоимость запроса в USD
            $table->decimal('cost_usd', 10, 6)->nullable();
            
            // Задержка выполнения в миллисекундах
            $table->unsignedInteger('latency_ms');
            
            // Успешность запроса
            $table->boolean('is_successful')->default(true);
            
            // Сообщение об ошибке (если есть)
            $table->text('error_message')->nullable();
            
            // Дополнительные метаданные
            $table->jsonb('metadata')->nullable();
            
            $table->timestamps();
            
            // Индекс для аналитики
            $table->index(['created_at', 'is_successful']);
            $table->index(['model_name', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_logs');
    }
};
