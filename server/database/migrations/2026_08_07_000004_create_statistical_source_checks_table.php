<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_source_checks', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('source_id')
                ->constrained('statistical_sources')
                ->restrictOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 32);
            $table->text('candidate_url')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('content_length')->nullable();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
            $table->foreignId('downloaded_file_id')
                ->nullable()
                ->constrained('statistical_source_files')
                ->nullOnDelete();
            $table->string('error_code', 128)->nullable();
            $table->text('error_message')->nullable();
            $table->json('details_json')->nullable();
            $table->timestamps();

            $table->index(['source_id', 'started_at'], 'stat_checks_source_started_idx');
            $table->index(['status', 'started_at'], 'stat_checks_status_started_idx');
            $table->index('downloaded_file_id', 'stat_checks_downloaded_file_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_source_checks');
    }
};
