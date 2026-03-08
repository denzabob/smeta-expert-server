<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('revision_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('initiator_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['PENDING', 'IN_PROGRESS', 'NEEDS_MANUAL', 'READY', 'FAILED'])->default('PENDING');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('ok_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status'], 'revision_runs_project_status_idx');
        });

        Schema::create('revision_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_run_id')->constrained('revision_runs')->cascadeOnDelete();
            $table->foreignId('project_position_id')->constrained('project_positions')->cascadeOnDelete();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->text('source_url')->nullable();
            $table->enum('status', ['OK', 'BLOCKED', 'TIMEOUT', 'PARSE_ERROR', 'NO_TEMPLATE', 'NEEDS_MANUAL'])->default('NEEDS_MANUAL');
            $table->text('message')->nullable();
            $table->foreignId('price_history_id')->nullable()->constrained('material_price_histories')->nullOnDelete();
            $table->timestamps();

            $table->index(['revision_run_id', 'status'], 'revision_run_items_run_status_idx');
            $table->index(['project_position_id'], 'revision_run_items_position_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revision_run_items');
        Schema::dropIfExists('revision_runs');
    }
};
