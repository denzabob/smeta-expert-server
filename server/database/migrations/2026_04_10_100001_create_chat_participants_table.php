<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chat participants — members of a conversation.
 *
 * Even though a basic support dialog has only 1 customer + 1 admin,
 * a dedicated participant table allows future expansion:
 *   - multiple operators per conversation
 *   - bot / system as first-class participants
 *   - left_at for tracking when someone leaves
 *
 * user_id is NOT NULL: a participant without a real user is semantically
 * invalid in our support-chat domain. User soft-deletes are the norm;
 * hard-deletes are rare admin operations that must clean up participants
 * first (RESTRICT behaviour). If virtual participants (bot/system) are
 * added later, they will use a dedicated bot_id or is_virtual flag.
 *
 * Unique constraint on (conversation_id, user_id): one user per
 * conversation, regardless of role. Role changes are handled by updating
 * the existing row, not inserting a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conversation_id')
                ->constrained('chat_conversations')
                ->cascadeOnDelete();

            // NOT NULL + restrictOnDelete: participant without a user is invalid.
            // User model uses SoftDeletes so hard deletes are rare admin actions
            // that must explicitly clean up participants first.
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->string('role', 30); // customer, admin, bot, system
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            // --- Indexes ---
            $table->index('conversation_id');
            $table->index('user_id');
            $table->unique(
                ['conversation_id', 'user_id'],
                'chat_part_conv_user_uniq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
    }
};
