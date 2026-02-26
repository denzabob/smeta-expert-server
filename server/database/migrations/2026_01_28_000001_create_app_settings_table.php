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
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->jsonb('value')->nullable();
            $table->timestamps();

            $table->index('key');
        });

        // Добавляем дефолтные настройки LLM
        DB::table('app_settings')->insert([
            [
                'key' => 'llm.primary_provider',
                'value' => json_encode('openrouter'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'llm.fallback_providers',
                'value' => json_encode(['deepseek']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'llm.mode',
                'value' => json_encode('auto'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'llm.providers',
                'value' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
