<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ЭТАП 1: Превращение supplier_urls в очередь на обработку.
 * 
 * Добавляет поля для:
 * - Статусы очереди (pending, processing, done, failed, blocked)
 * - Блокировка воркером (locked_by, locked_at)
 * - Попытки и backoff (attempts, next_retry_at, last_attempt_at)
 * - Информация об ошибках (last_error_code, last_error_message)
 * - Отслеживание парсинга (last_parsed_at)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_urls', function (Blueprint $table) {
            // Статус очереди
            $table->enum('status', ['pending', 'processing', 'done', 'failed', 'blocked'])
                ->default('pending')
                ->after('validation_error')
                ->comment('Статус обработки URL');
            
            // Попытки парсинга (отдельно от retries для валидации)
            $table->integer('attempts')->default(0)->after('status')
                ->comment('Количество попыток парсинга');
            
            // Блокировка воркером
            $table->string('locked_by', 64)->nullable()->after('attempts')
                ->comment('ID воркера, который обрабатывает URL');
            $table->timestamp('locked_at')->nullable()->after('locked_by')
                ->comment('Время блокировки воркером');
            
            // Временные метки обработки
            $table->timestamp('last_attempt_at')->nullable()->after('locked_at')
                ->comment('Время последней попытки парсинга');
            $table->timestamp('last_parsed_at')->nullable()->after('last_attempt_at')
                ->comment('Время последнего успешного парсинга');
            $table->timestamp('next_retry_at')->nullable()->after('last_parsed_at')
                ->comment('Когда можно повторить попытку');
            
            // Информация об ошибке
            $table->string('last_error_code', 50)->nullable()->after('next_retry_at')
                ->comment('Код последней ошибки');
            $table->text('last_error_message')->nullable()->after('last_error_code')
                ->comment('Текст последней ошибки');
            
            // Новые индексы для эффективной выборки из очереди
            $table->index(['supplier_name', 'status', 'next_retry_at'], 'idx_queue_claim');
            $table->index(['supplier_name', 'material_type', 'status', 'next_retry_at'], 'idx_queue_claim_type');
            $table->index(['supplier_name', 'last_parsed_at'], 'idx_reparsing');
            $table->index(['status', 'locked_at'], 'idx_stale_processing');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_urls', function (Blueprint $table) {
            // Удаляем индексы
            $table->dropIndex('idx_queue_claim');
            $table->dropIndex('idx_queue_claim_type');
            $table->dropIndex('idx_reparsing');
            $table->dropIndex('idx_stale_processing');
            
            // Удаляем колонки
            $table->dropColumn([
                'status',
                'attempts',
                'locked_by',
                'locked_at',
                'last_attempt_at',
                'last_parsed_at',
                'next_retry_at',
                'last_error_code',
                'last_error_message',
            ]);
        });
    }
};
