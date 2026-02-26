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
        Schema::create('price_import_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('price_list_version_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('supplier_id')->nullable()->constrained()->onDelete('set null');
            
            // Target type
            $table->enum('target_type', ['operations', 'materials']);
            
            // File info
            $table->string('file_path')->nullable();
            $table->string('storage_disk')->default('local');
            $table->string('original_filename')->nullable();
            $table->enum('file_type', ['xlsx', 'xls', 'csv', 'html', 'paste'])->nullable();
            
            // Status machine (расширенный)
            $table->enum('status', [
                'created',            // Сессия создана, файл загружен
                'parsing_failed',     // Ошибка парсинга (плохой формат)
                'mapping_required',   // Нужен маппинг колонок
                'resolution_required', // Нужно ручное сопоставление
                'execution_running',  // Выполняется запись
                'completed',          // Успешно завершено
                'execution_failed',   // Ошибка при записи
                'cancelled'           // Импорт отменён
            ])->default('created');
            
            // Parsing & Mapping
            $table->unsignedInteger('header_row_index')->default(0);
            $table->unsignedInteger('sheet_index')->default(0);
            $table->json('column_mapping')->nullable()->comment('{"0": "name", "1": "cost_per_unit", ...}');
            $table->json('options')->nullable()->comment('csv_encoding, csv_delimiter, etc.');
            
            // Raw data cache
            $table->json('raw_rows')->nullable()->comment('Parsed rows before processing');
            
            // Resolution queue (результат dry run)
            $table->json('resolution_queue')->nullable()->comment('JSON array of rows needing resolution');
            
            // Stats
            $table->json('stats')->nullable()->comment('total, auto_matched, ambiguous, new, ignored');
            
            // Result
            $table->json('result')->nullable()->comment('created_count, updated_count, errors, etc.');
            
            // Error info
            $table->text('error_message')->nullable();
            $table->json('error_details')->nullable();
            
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['price_list_version_id']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_import_sessions');
    }
};
