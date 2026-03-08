<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_dimension_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('priority')->default(100);
            $table->string('material_type', 32)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('rule_type', 32)->default('regex');
            $table->json('config');
            $table->string('example_input', 1024)->nullable();
            $table->json('expected_result')->nullable();
            $table->decimal('confidence', 4, 2)->default(0.75);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'priority']);
            $table->index(['material_type', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_dimension_rules');
    }
};
