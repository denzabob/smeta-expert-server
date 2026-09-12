<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_storage_cleanup_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('kind', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->index();
            $table->timestamps();

            $table->unique(['disk', 'path'], 'expert_storage_cleanup_tasks_disk_path_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_storage_cleanup_tasks');
    }
};
