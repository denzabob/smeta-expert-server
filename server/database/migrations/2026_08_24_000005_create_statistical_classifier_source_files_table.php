<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statistical_classifier_source_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('classifier_id');
            $table->enum('trust_tier', [
                'official_authoritative',
                'operator_official_upload',
                'reference_fixture',
            ]);
            $table->text('source_page_url')->nullable();
            $table->text('download_url')->nullable();
            $table->text('resolved_url')->nullable();
            $table->string('original_filename');
            $table->string('storage_disk', 64);
            $table->text('storage_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('etag', 512)->nullable();
            $table->dateTime('last_modified_at')->nullable();
            $table->dateTime('downloaded_at')->nullable();
            $table->string('declared_version_label', 128)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique(
                ['classifier_id', 'sha256'],
                'stat_cls_src_classifier_sha_unique'
            );
            $table->unique(
                ['classifier_id', 'id'],
                'stat_cls_src_classifier_id_unique'
            );
            $table->foreign('classifier_id', 'stat_cls_src_classifier_fk')
                ->references('id')
                ->on('statistical_classifiers')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statistical_classifier_source_files');
    }
};
