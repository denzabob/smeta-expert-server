<?php

use App\Enums\Chat\ConversationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support chat conversations.
 *
 * Represents a support dialog between a user and admin(s).
 * Designed for future expansion: AI bot participants, multiple operators,
 * extended statuses (waiting_for_user, waiting_for_admin).
 *
 * Lifecycle:
 *   open → pending (awaiting admin) → closed
 *   open → closed  (resolved immediately)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();

            // User who initiated the conversation.
            // Nullable + nullOnDelete: if user is deleted, conversation history is preserved.
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            // Assigned admin operator (nullable until someone picks up)
            $table->unsignedBigInteger('assigned_admin_id')->nullable();
            $table->foreign('assigned_admin_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->string('status', 30)->default(ConversationStatus::OPEN->value);
            $table->string('subject', 255)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // --- Indexes ---
            // (status, last_message_at): global admin list sorted by recency per status.
            $table->index(['status', 'last_message_at'], 'chat_conv_status_last_msg_idx');
            // (assigned_admin_id, status, last_message_at): "my open tickets" view.
            $table->index(
                ['assigned_admin_id', 'status', 'last_message_at'],
                'chat_conv_admin_status_last_msg_idx'
            );
            // (created_by_user_id, status): user's own conversation list.
            $table->index(['created_by_user_id', 'status'], 'chat_conv_user_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
