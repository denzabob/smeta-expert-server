<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_source_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('dataset_id')
                ->constrained('statistical_datasets')
                ->restrictOnDelete();
            $table->foreignId('source_id')
                ->nullable()
                ->constrained('statistical_sources')
                ->restrictOnDelete();
            $table->string('acquisition_method', 32);
            $table->unsignedSmallInteger('reporting_year')->nullable();
            $table->unsignedTinyInteger('reporting_month')->nullable();
            $table->text('source_url')->nullable();
            $table->string('original_filename');
            $table->text('stored_path');
            $table->string('storage_disk', 64);
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size');
            $table->char('sha256', 64);
            $table->string('http_etag')->nullable();
            $table->string('http_last_modified')->nullable();
            $table->dateTime('downloaded_at')->nullable();
            $table->foreignId('uploaded_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('detected_at');
            $table->string('status', 32);
            $table->string('validation_status', 32);
            $table->json('validation_summary_json')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->foreignId('activated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->dateTime('activated_at')->nullable();
            $table->unsignedBigInteger('supersedes_file_id')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->foreign('supersedes_file_id')
                ->references('id')
                ->on('statistical_source_files')
                ->restrictOnDelete();
            $table->unique(['dataset_id', 'sha256'], 'stat_files_dataset_sha_unique');
            $table->index(
                ['dataset_id', 'reporting_year', 'reporting_month', 'status'],
                'stat_files_dataset_period_status_idx'
            );
            $table->index(['source_id', 'detected_at'], 'stat_files_source_detected_idx');
            $table->index(['status', 'detected_at'], 'stat_files_status_detected_idx');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE statistical_source_files '
                .'ADD CONSTRAINT stat_files_month_chk '
                .'CHECK (reporting_month IS NULL OR reporting_month BETWEEN 1 AND 12)'
            );
            DB::statement(
                'ALTER TABLE statistical_source_files '
                .'ADD CONSTRAINT stat_files_period_pair_chk '
                .'CHECK ((reporting_year IS NULL AND reporting_month IS NULL) '
                .'OR (reporting_year IS NOT NULL AND reporting_month IS NOT NULL))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_source_files');
    }
};
