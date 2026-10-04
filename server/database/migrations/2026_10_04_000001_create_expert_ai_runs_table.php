<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('expert_project_id')->constrained('expert_projects')->cascadeOnDelete();
            $table->foreignId('expert_conversation_id')->constrained('expert_conversations')->cascadeOnDelete();
            $table->uuid('client_message_id');
            $table->foreignId('user_message_id')->nullable()->constrained('expert_messages')->nullOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained('expert_messages')->nullOnDelete();
            $table->string('status', 32)->default('running');
            $table->string('stage', 32)->default('created');
            $table->string('requested_mode', 32);
            $table->string('resolved_mode', 32)->nullable();
            $table->unsignedInteger('selected_material_count')->default(0);
            $table->unsignedInteger('persisted_material_count')->default(0);
            $table->unsignedInteger('resolved_material_count')->default(0);
            $table->unsignedInteger('active_material_count')->default(0);
            $table->string('provider', 64)->nullable();
            $table->string('model', 255)->nullable();
            $table->string('upstream_provider', 64)->nullable();
            $table->string('upstream_model', 255)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('first_token_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('finish_reason', 64)->nullable();
            $table->string('error_code', 100)->nullable();
            $table->boolean('retryable')->default(false);
            $table->string('last_activity_code', 100)->nullable();
            $table->json('metadata');
            $table->timestamps();

            $table->index(['user_id', 'status', 'started_at'], 'expert_ai_runs_user_status_started_idx');
            $table->index(['expert_project_id', 'started_at'], 'expert_ai_runs_project_started_idx');
            $table->index(['expert_conversation_id', 'started_at'], 'expert_ai_runs_conversation_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_ai_runs');
    }
};
