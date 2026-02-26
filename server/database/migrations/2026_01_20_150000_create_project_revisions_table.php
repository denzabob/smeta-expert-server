<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_revisions', function (Blueprint $table) {
            // Primary key
            $table->uuid('id')->primary();

            // Foreign keys
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('created_by_user_id');

            // Revision metadata
            $table->unsignedInteger('number');  // Auto-incrementing within project
            $table->enum('status', ['locked', 'published', 'stale'])->default('locked');

            // Snapshot storage
            $table->longText('snapshot_json')->nullable();  // Full JSON snapshot
            $table->char('snapshot_hash', 64)->nullable();  // SHA256 of canonical JSON

            // Version information
            $table->string('app_version', 50)->nullable();
            $table->string('calculation_engine_version', 50)->nullable();

            // Timestamps
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('stale_at')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            // Indexes
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('created_by_user_id')->references('id')->on('users')->onDelete('restrict');
            $table->index(['project_id', 'number']);
            $table->index(['project_id', 'status']);
            $table->index('snapshot_hash');
            $table->unique(['project_id', 'number']);  // Ensure number is unique per project
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_revisions');
    }
};
