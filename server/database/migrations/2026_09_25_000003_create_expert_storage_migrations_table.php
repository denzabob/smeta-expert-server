<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_storage_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->nullable()->unique()
                ->constrained('expert_project_materials')->nullOnDelete();
            $table->string('source_disk', 32);
            $table->string('source_key');
            $table->string('target_disk', 32);
            $table->string('target_key');
            $table->unsignedBigInteger('source_size')->nullable();
            $table->char('source_sha256', 64)->nullable();
            $table->unsignedBigInteger('target_size')->nullable();
            $table->char('target_sha256', 64)->nullable();
            $table->boolean('target_created')->default(false);
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('copied_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('switched_at')->nullable();
            $table->timestamp('cleanup_after')->nullable();
            $table->timestamp('cleaned_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at'], 'expert_storage_migrations_status_updated_idx');
        });
    }

    public function down(): void
    {
        if (DB::table('expert_storage_migrations')->exists()) {
            throw new \RuntimeException('Cannot remove Expert storage migration records while migration recovery data exists.');
        }

        Schema::dropIfExists('expert_storage_migrations');
    }
};
