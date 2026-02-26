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
        Schema::create('price_list_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_list_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('version_number')->comment('Автоинкремент внутри price_list');
            $table->string('sha256', 64)->nullable()->comment('Хэш содержимого для дедупликации');
            $table->string('currency', 3)->default('RUB');
            $table->date('effective_date')->nullable()->comment('Дата начала действия прайса');
            $table->timestamp('captured_at')->nullable()->comment('Дата импорта/захвата');
            
            // File storage
            $table->string('file_path')->nullable();
            $table->string('storage_disk')->default('local');
            $table->string('original_filename')->nullable();
            
            // Status
            $table->enum('status', [
                'draft',      // Создана, но не завершена
                'active',     // Активная версия
                'archived'    // Архивная версия
            ])->default('draft');
            
            $table->json('metadata')->nullable()->comment('row_count, column_count, parsing_notes, etc.');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['price_list_id', 'version_number']);
            $table->unique(['price_list_id', 'sha256']);
            $table->index(['price_list_id', 'status']);
            $table->index(['effective_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_list_versions');
    }
};
