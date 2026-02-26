<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revision_publications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('project_revision_id');
            $table->string('public_id', 32)->unique();
            $table->string('public_token_hash', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->enum('access_level', ['public_readonly', 'restricted_token', 'auth_only'])->default('public_readonly');
            $table->timestamps();

            $table->index(['project_revision_id', 'is_active']);
            $table->foreign('project_revision_id')->references('id')->on('project_revisions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revision_publications');
    }
};
