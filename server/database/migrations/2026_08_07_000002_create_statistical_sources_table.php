<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_sources', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dataset_id')
                ->constrained('statistical_datasets')
                ->restrictOnDelete();
            $table->string('code', 128);
            $table->string('name');
            $table->text('source_page_url')->nullable();
            $table->text('download_url_template')->nullable();
            $table->string('filename_template')->nullable();
            $table->string('http_method', 8)->default('GET');
            $table->boolean('is_enabled')->default(true);
            $table->boolean('automatic_check_enabled')->default(false);
            $table->dateTime('last_checked_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('next_check_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->string('last_error_code', 128)->nullable();
            $table->text('last_error_message')->nullable();
            $table->json('settings_json')->nullable();
            $table->timestamps();

            $table->unique(['dataset_id', 'code'], 'stat_sources_dataset_code_unique');
            $table->index(['dataset_id', 'is_enabled'], 'stat_sources_dataset_enabled_idx');
            $table->index(
                ['is_enabled', 'automatic_check_enabled', 'next_check_at'],
                'stat_sources_enabled_auto_next_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_sources');
    }
};
