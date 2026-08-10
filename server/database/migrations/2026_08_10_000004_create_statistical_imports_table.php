<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_imports', function (Blueprint $table) {
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
            $table->unsignedInteger('attempt_no');
            $table->foreignId('retry_of_import_id')
                ->nullable()
                ->constrained('statistical_imports')
                ->restrictOnDelete();
            $table->string('status', 32);
            $table->char('successful_dedupe_key', 64)->nullable()->unique();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('published_at')->nullable();
            $table->dateTime('superseded_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->unsignedBigInteger('rows_scanned')->default(0);
            $table->unsignedBigInteger('observations_parsed')->default(0);
            $table->unsignedBigInteger('observations_valid')->default(0);
            $table->unsignedBigInteger('observations_rejected')->default(0);
            $table->unsignedInteger('warnings_count')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->foreignId('initiated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('published_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('supersedes_import_id')
                ->nullable()
                ->constrained('statistical_imports')
                ->restrictOnDelete();
            $table->string('failure_code', 128)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('validation_summary_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['source_file_id', 'importer_code', 'importer_version', 'attempt_no'],
                'stat_imports_file_importer_version_attempt_unique'
            );
            $table->index(['dataset_id', 'status'], 'stat_imports_dataset_status_idx');
            $table->index(['status', 'created_at'], 'stat_imports_status_created_idx');
            $table->index(
                ['importer_code', 'importer_version'],
                'stat_imports_importer_version_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_imports');
    }
};
