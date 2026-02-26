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
        Schema::create('work_presets', function (Blueprint $table) {
            $table->id();
            
            // Нормализованный заголовок работы
            $table->string('normalized_title', 500)->index();
            
            // Хеш контекста (md5 = 32 символа)
            $table->char('context_hash', 32)->index();
            
            // JSON с контекстом (только значимые параметры)
            $table->jsonb('context_json')->nullable();
            
            // JSON с этапами работы
            $table->jsonb('steps_json');
            
            // Общее количество часов
            $table->decimal('total_hours', 8, 2);
            
            // Fingerprint для идентификации уникальных наборов этапов
            $table->char('fingerprint', 32)->index();
            
            // Счетчик использований
            $table->unsignedInteger('usage_count')->default(1);
            
            // Статус пресета
            $table->enum('status', ['draft', 'candidate', 'verified', 'deprecated'])->default('draft');
            
            // Источник создания
            $table->enum('source', ['manual', 'ai', 'imported'])->default('manual');
            
            $table->timestamps();
            
            // Составной индекс для быстрого поиска
            $table->index(['context_hash', 'normalized_title', 'status']);
            
            // Уникальный индекс для предотвращения дубликатов
            $table->unique(['context_hash', 'normalized_title', 'fingerprint'], 'work_presets_unique_combo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_presets');
    }
};
