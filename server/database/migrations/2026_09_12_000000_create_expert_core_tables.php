<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_projects', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain', 64);
            $table->string('work_type', 64);
            $table->string('customer')->nullable();
            $table->text('object_summary')->nullable();
            $table->string('address')->nullable();
            $table->date('research_date')->nullable();
            $table->json('research_questions')->nullable();
            $table->string('status', 64)->default('active');
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('expert_research_objects', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('expert_project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 64)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['expert_project_id', 'sort_order']);
        });

        Schema::create('expert_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('expert_project_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
            $table->index(['expert_project_id', 'updated_at']);
        });

        Schema::create('expert_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('expert_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32);
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['expert_conversation_id', 'created_at']);
        });

        Schema::create('expert_project_materials', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('expert_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_name');
            $table->string('storage_path');
            $table->string('mime_type', 128);
            $table->string('extension', 16);
            $table->unsignedBigInteger('size');
            $table->string('category', 32);
            $table->string('status', 32)->default('uploaded');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['expert_project_id', 'created_at']);
        });

        Schema::create('expert_findings', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('expert_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('research_object_id')->nullable()->constrained('expert_research_objects')->nullOnDelete();
            $table->string('type', 64);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('value')->nullable();
            $table->string('unit', 64)->nullable();
            $table->string('status', 32)->default('expert_confirmed');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['expert_project_id', 'created_at']);
            $table->index(['expert_project_id', 'type', 'status'], 'expert_findings_project_type_status_idx');
        });

        Schema::create('expert_finding_material', function (Blueprint $table) {
            $table->foreignId('expert_finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expert_project_material_id')->constrained()->cascadeOnDelete();
            $table->primary(['expert_finding_id', 'expert_project_material_id'], 'expert_finding_material_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_finding_material');
        Schema::dropIfExists('expert_findings');
        Schema::dropIfExists('expert_project_materials');
        Schema::dropIfExists('expert_messages');
        Schema::dropIfExists('expert_conversations');
        Schema::dropIfExists('expert_research_objects');
        Schema::dropIfExists('expert_projects');
    }
};
