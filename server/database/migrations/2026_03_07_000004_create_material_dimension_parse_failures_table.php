<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_dimension_parse_failures', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->text('raw_text');
            $table->text('normalized_text');
            $table->string('material_type', 32)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('parse_error_reason', 128)->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->integer('resolved_length_mm')->nullable();
            $table->integer('resolved_width_mm')->nullable();
            $table->decimal('resolved_thickness_mm', 8, 2)->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->json('last_result')->nullable();
            $table->timestamps();

            $table->index(['material_type', 'source']);
            $table->index(['resolved_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_dimension_parse_failures');
    }
};
