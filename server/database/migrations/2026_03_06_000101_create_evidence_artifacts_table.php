<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evidence_artifacts')) {
            return;
        }

        Schema::create('evidence_artifacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('material_id')->constrained('materials')->cascadeOnDelete();
            $table->foreignId('revision_run_id')->nullable()->constrained('revision_runs')->nullOnDelete();
            $table->foreignId('revision_run_item_id')->nullable()->constrained('revision_run_items')->nullOnDelete();
            $table->enum('mode', ['auto', 'manual']);
            $table->text('source_url_raw')->nullable();
            $table->string('source_url_normalized', 2048)->nullable();
            $table->string('source_domain', 255)->nullable();
            $table->string('page_type', 64)->nullable();
            $table->string('block_type', 64)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->foreignId('parser_profile_id')->nullable()->constrained('parser_supplier_collect_profiles')->nullOnDelete();
            $table->string('parser_version', 64)->nullable();
            $table->decimal('extracted_price', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('extracted_name', 1024)->nullable();
            $table->string('extracted_article', 255)->nullable();
            $table->string('screenshot_path', 255)->nullable();
            $table->string('screenshot_sha256', 64)->nullable();
            $table->string('html_sha256', 64)->nullable();
            $table->unsignedSmallInteger('viewport_w')->nullable();
            $table->unsignedSmallInteger('viewport_h')->nullable();
            $table->string('user_agent_hash', 64)->nullable();
            $table->unsignedTinyInteger('confidence_score')->nullable();
            $table->unsignedTinyInteger('trust_score')->nullable();
            $table->string('reason_code', 64)->nullable();
            $table->json('reason_details_json')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['material_id', 'mode'], 'evidence_artifacts_material_mode_idx');
            $table->index(['source_domain'], 'evidence_artifacts_domain_idx');
            $table->index(['reason_code'], 'evidence_artifacts_reason_idx');
            $table->index(['source_url_normalized'], 'evidence_artifacts_norm_url_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_artifacts');
    }
};

