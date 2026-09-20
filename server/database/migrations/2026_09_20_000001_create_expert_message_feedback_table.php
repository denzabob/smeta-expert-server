<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expert_message_feedback', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('message_id')->constrained('expert_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('rating', 16);
            $table->string('reason_code', 64)->nullable();
            $table->text('comment')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('model', 255)->nullable();
            $table->string('requested_mode', 32)->nullable();
            $table->string('resolved_mode', 32)->nullable();
            $table->uuid('run_id')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'message_id']);
            $table->index(['rating', 'created_at']);
            $table->index(['provider', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expert_message_feedback');
    }
};
