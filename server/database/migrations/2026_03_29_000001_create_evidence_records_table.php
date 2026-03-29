<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('cost_component', 40);
            $table->string('source_type', 40);
            $table->string('capture_method', 40);
            $table->string('verification_status', 40)->default('pending');

            // Source observation
            $table->string('source_url')->nullable();
            $table->string('source_domain')->nullable();
            $table->decimal('observed_price', 12, 2)->nullable();
            $table->string('currency', 10)->default('RUB');
            $table->timestamp('observed_at')->nullable();

            // Extracted data
            $table->string('extracted_name')->nullable();
            $table->string('extracted_article')->nullable();
            $table->json('metadata_json')->nullable();

            // Scoring
            $table->unsignedSmallInteger('confidence_score')->nullable();
            $table->unsignedSmallInteger('trust_score')->nullable();

            // Ownership
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index('cost_component');
            $table->index('verification_status');
        });

        Schema::create('generic_evidence_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('evidence_record_id')->constrained('evidence_records')->cascadeOnDelete();
            $table->string('asset_type', 40);
            $table->string('file_path');
            $table->string('original_filename')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('evidence_record_id');
        });

        Schema::create('evidence_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evidence_record_id')->constrained('evidence_records')->cascadeOnDelete();
            $table->morphs('linkable');
            $table->string('relation_type', 40)->default('primary');
            $table->timestamps();

            $table->unique(['evidence_record_id', 'linkable_type', 'linkable_id'], 'evidence_links_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_links');
        Schema::dropIfExists('generic_evidence_assets');
        Schema::dropIfExists('evidence_records');
    }
};
