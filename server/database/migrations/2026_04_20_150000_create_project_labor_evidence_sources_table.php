<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_labor_evidence_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('labor_evidence_source_id')->constrained('labor_evidence_sources')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'labor_evidence_source_id'], 'project_labor_evidence_sources_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_labor_evidence_sources');
    }
};
