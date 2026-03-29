<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evidence_assets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('evidence_artifact_id')->constrained('evidence_artifacts')->cascadeOnDelete();
            $table->string('asset_type', 30);          // screenshot, document, receipt, price_list
            $table->string('file_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index('evidence_artifact_id', 'evidence_assets_artifact_idx');
            $table->index('asset_type', 'evidence_assets_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_assets');
    }
};
