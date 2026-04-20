<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_selected_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->string('source_type', 20);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('selected_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('selected_at');
            $table->timestamps();

            $table->unique(['operation_id', 'selected_by'], 'operation_selected_sources_unique');
            $table->index(['source_type', 'source_id'], 'operation_selected_sources_source_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_selected_sources');
    }
};
