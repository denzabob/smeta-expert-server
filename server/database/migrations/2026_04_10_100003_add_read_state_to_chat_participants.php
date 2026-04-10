<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add read-state tracking to chat_participants.
 *
 * Uses the lightest possible approach: a single pointer per participant.
 * Unread count = messages.where('id', '>', last_read_message_id).count()
 *
 * No FK on last_read_message_id: chat_messages uses soft deletes, so
 * hard-deletes never occur in normal operation. A plain bigint is safe
 * and avoids FK edge cases with soft-deleted rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_participants', function (Blueprint $table) {
            $table->unsignedBigInteger('last_read_message_id')->nullable()->after('left_at');
            $table->timestamp('last_read_at')->nullable()->after('last_read_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_participants', function (Blueprint $table) {
            $table->dropColumn(['last_read_message_id', 'last_read_at']);
        });
    }
};
