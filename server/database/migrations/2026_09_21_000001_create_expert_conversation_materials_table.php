<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_conversation_materials', function (Blueprint $table) {
            $table->foreignId('expert_conversation_id')->constrained('expert_conversations')->cascadeOnDelete();
            $table->foreignId('expert_project_material_id')->constrained('expert_project_materials')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['expert_conversation_id', 'expert_project_material_id'], 'expert_conversation_materials_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_conversation_materials');
    }
};
