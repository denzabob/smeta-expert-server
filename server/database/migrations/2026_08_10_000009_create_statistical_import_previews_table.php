<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_import_previews', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dataset_id')
                ->constrained('statistical_datasets')
                ->restrictOnDelete();
            $table->foreignId('source_file_id')
                ->constrained('statistical_source_files')
                ->restrictOnDelete();
            $table->string('importer_code', 128);
            $table->string('importer_version', 64);
            $table->string('status', 32);
            $table->char('cache_key', 64);
            $table->foreignId('requested_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->unsignedInteger('sheets_total')->default(0);
            $table->unsignedInteger('supported_sheets')->default(0);
            $table->unsignedInteger('ignored_sheets')->default(0);
            $table->unsignedBigInteger('commodity_occurrences')->default(0);
            $table->unsignedBigInteger('unique_classifier_items')->default(0);
            $table->unsignedBigInteger('observation_candidates')->default(0);
            $table->unsignedBigInteger('numeric_count')->default(0);
            $table->unsignedBigInteger('missing_count')->default(0);
            $table->unsignedBigInteger('footnoted_count')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->unsignedInteger('fatal_errors_count')->default(0);
            $table->json('result_json')->nullable();
            $table->string('failure_code', 128)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('cache_key', 'stat_import_previews_cache_key_idx');
            $table->index(
                ['source_file_id', 'created_at'],
                'stat_import_previews_source_created_idx'
            );
            $table->index(
                ['dataset_id', 'status'],
                'stat_import_previews_dataset_status_idx'
            );
            $table->index(
                ['status', 'expires_at'],
                'stat_import_previews_status_expires_idx'
            );
            $table->index('created_at', 'stat_import_previews_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_import_previews');
    }
};
