<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_material_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expert_project_material_id')->unique()->constrained('expert_project_materials')->cascadeOnDelete();
            $table->string('schema_version', 32);
            $table->string('state', 32);
            $table->char('source_sha256', 64)->nullable();
            $table->char('metadata_hash', 64);
            $table->char('content_fingerprint', 64)->nullable();
            $table->json('descriptor');
            $table->text('routing_text')->nullable();
            $table->string('content_source', 64)->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('built_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_material_identities');
    }
};
