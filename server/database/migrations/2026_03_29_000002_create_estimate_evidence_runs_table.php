<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estimate_evidence_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 40)->default('pending');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('completed_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->json('metadata_json')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('estimate_evidence_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('evidence_run_id')->constrained('estimate_evidence_runs')->cascadeOnDelete();
            $table->string('cost_component', 40);
            $table->string('status', 40)->default('pending');
            $table->string('resolution_type', 40)->nullable();

            // Polymorphic subject (e.g. ProjectPosition, ProjectFitting, Expense, etc.)
            $table->nullableMorphs('subject');

            // Link to the generic evidence record once resolved
            $table->foreignId('evidence_record_id')->nullable()->constrained('evidence_records')->nullOnDelete();

            $table->string('source_url')->nullable();
            $table->json('diagnostics_json')->nullable();
            $table->timestamps();

            $table->index(['evidence_run_id', 'status']);
            $table->index('cost_component');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimate_evidence_items');
        Schema::dropIfExists('estimate_evidence_runs');
    }
};
