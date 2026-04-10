<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat messages within conversations.
 *
 * Supports message types: text, system, file, ai.
 * Uses soft deletes so message history can be recovered.
 * meta_json stores type-specific payload (file URL, AI model info, etc.).
 *
 * sender_id is nullable + nullOnDelete to preserve message history
 * when a user account is deleted. sender_role is denormalized for
 * efficient rendering without JOINing participants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();

            // Nullable + nullOnDelete: preserve message even if sender is deleted
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->foreign('sender_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->string('sender_role', 30); // customer, admin, bot, system
            $table->string('type', 30)->default('text'); // text, system, file, ai
            $table->text('body');
            $table->json('meta_json')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // --- Indexes ---
            // (conversation_id, created_at): primary access — messages paged by time.
            // Also covers WHERE conversation_id = ? AND deleted_at IS NULL ORDER BY created_at
            // since soft-deleted rows are rare and filtered cheaply after the range scan.
            $table->index(['conversation_id', 'created_at'], 'chat_msg_conv_created_idx');
            $table->index('sender_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
