<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_ai_run_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expert_ai_run_id')->constrained('expert_ai_runs')->cascadeOnDelete();
            $table->uuid('run_id');
            $table->unsignedBigInteger('seq');
            $table->string('source', 24);
            $table->string('level', 16);
            $table->string('event_code', 100);
            $table->string('stage', 32);
            $table->string('status', 32);
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['run_id', 'seq'], 'expert_ai_run_events_run_seq_unique');
            $table->index(['expert_ai_run_id', 'seq'], 'expert_ai_run_events_fk_seq_idx');
            $table->index(['event_code', 'created_at'], 'expert_ai_run_events_code_created_idx');
            $table->index(['level', 'created_at'], 'expert_ai_run_events_level_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_ai_run_events');
    }
};
