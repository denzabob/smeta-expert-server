<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_application_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operation_id')->constrained('operations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mode', 20)->default('automatic');
            $table->string('applies_to', 40);
            $table->string('material_type', 40)->nullable();
            $table->foreignId('material_id')->nullable()->constrained('materials')->nullOnDelete();
            $table->string('quantity_method', 40);
            $table->json('conditions_json')->nullable();
            $table->json('quantity_config_json')->nullable();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index(['is_enabled', 'applies_to', 'material_type'], 'operation_application_rules_match_idx');
            $table->index(['user_id', 'is_enabled'], 'operation_application_rules_user_idx');
            $table->index(['material_id', 'is_enabled'], 'operation_application_rules_material_idx');
            $table->index(['priority', 'id'], 'operation_application_rules_priority_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operation_application_rules');
    }
};
