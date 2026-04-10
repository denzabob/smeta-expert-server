<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\Chat\ConversationStatus;
use App\Enums\Chat\MessageType;
use App\Enums\Chat\ParticipantRole;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatMessage;
use App\Models\Chat\ChatParticipant;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SupportChatService
{
    private const MESSAGES_LIMIT = 50;
    private const ADMIN_LIST_PER_PAGE = 20;

    // =========================================================================
    // User-facing operations
    // =========================================================================

    /**
     * Return the active support conversation for the user.
     * Creates a new one (with the customer participant) if none exists.
     * A user can have at most one active (open/pending) conversation at a time.
     */
    public function getOrCreateForUser(User $user): ChatConversation
    {
        $conversation = ChatConversation::where('created_by_user_id', $user->id)
            ->whereIn('status', array_map(
                fn (ConversationStatus $s) => $s->value,
                ConversationStatus::activeStatuses()
            ))
            ->latest('id')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return DB::transaction(function () use ($user) {
            $conversation = ChatConversation::create([
                'created_by_user_id' => $user->id,
                'status'             => ConversationStatus::OPEN,
                'last_message_at'    => now(),
            ]);

            $this->ensureParticipant($conversation, $user, ParticipantRole::CUSTOMER);

            return $conversation;
        });
    }

    /**
     * Send a text message as the user (customer role).
     */
    public function sendUserMessage(ChatConversation $conversation, User $user, string $body): ChatMessage
    {
        $this->assertNotClosed($conversation);

        return DB::transaction(function () use ($conversation, $user, $body) {
            $message = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $user->id,
                'sender_role'     => ParticipantRole::CUSTOMER,
                'type'            => MessageType::TEXT,
                'body'            => $body,
            ]);

            $conversation->update(['last_message_at' => $message->created_at]);

            return $message;
        });
    }

    // =========================================================================
    // Admin-facing operations
    // =========================================================================

    /**
     * Send a text message as an admin.
     * Auto-assigns the admin to the conversation if it has no assigned admin yet.
     * Ensures the admin is a participant (creates participant record lazily).
     */
    public function sendAdminMessage(ChatConversation $conversation, User $admin, string $body): ChatMessage
    {
        $this->assertNotClosed($conversation);

        return DB::transaction(function () use ($conversation, $admin, $body) {
            $this->ensureParticipant($conversation, $admin, ParticipantRole::ADMIN);

            if ($conversation->assigned_admin_id === null) {
                $conversation->update(['assigned_admin_id' => $admin->id]);
                $conversation->refresh();
            }

            $message = ChatMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id'       => $admin->id,
                'sender_role'     => ParticipantRole::ADMIN,
                'type'            => MessageType::TEXT,
                'body'            => $body,
            ]);

            $conversation->update(['last_message_at' => $message->created_at]);

            return $message;
        });
    }

    /**
     * Assign a specific admin to the conversation.
     * Ensures the admin is added as a participant.
     */
    public function assignAdmin(ChatConversation $conversation, User $admin): ChatConversation
    {
        return DB::transaction(function () use ($conversation, $admin) {
            $this->ensureParticipant($conversation, $admin, ParticipantRole::ADMIN);

            $conversation->update(['assigned_admin_id' => $admin->id]);
            $conversation->refresh();

            return $conversation;
        });
    }

    /**
     * Paginated list of conversations for the admin panel.
     * Supports filtering by status, assigned_admin_id, and user search.
     */
    public function listForAdmin(array $filters = [], int $perPage = self::ADMIN_LIST_PER_PAGE): LengthAwarePaginator
    {
        $perPage = min($perPage, 100);

        $query = ChatConversation::query()
            ->with(['creator:id,name,email', 'assignedAdmin:id,name']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (array_key_exists('assigned_admin_id', $filters)) {
            $value = $filters['assigned_admin_id'];
            $value === null
                ? $query->whereNull('assigned_admin_id')
                : $query->where('assigned_admin_id', (int) $value);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->whereHas('creator', function ($q) use ($search) {
                $q->withTrashed()
                  ->where(function ($q) use ($search) {
                      $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        return $query->orderByDesc('last_message_at')->paginate($perPage);
    }

    /**
     * Load a conversation with all details for the admin view.
     * Returns participants, creator, assigned admin, and messages.
     */
    public function getWithDetails(ChatConversation $conversation): ChatConversation
    {
        return $conversation->load([
            'creator:id,name,email',
            'assignedAdmin:id,name',
            'participants.user:id,name',
            'messages' => fn ($q) => $q->with('sender:id,name')->orderBy('id', 'asc'),
        ]);
    }

    // =========================================================================
    // Shared operations
    // =========================================================================

    /**
     * List messages for a conversation.
     *
     * - Without after_id: returns the latest MESSAGES_LIMIT messages in
     *   ascending (chronological) order — suitable for initial load.
     * - With after_id: returns all messages with id > after_id in ascending
     *   order — suitable for polling. Capped at MESSAGES_LIMIT.
     */
    public function listMessages(ChatConversation $conversation, int $afterId = 0): Collection
    {
        if ($afterId > 0) {
            return $conversation->messages()
                ->with('sender:id,name')
                ->where('id', '>', $afterId)
                ->orderBy('id', 'asc')
                ->limit(self::MESSAGES_LIMIT)
                ->get();
        }

        // Initial load: fetch latest N in reverse, then flip to chronological.
        return $conversation->messages()
            ->with('sender:id,name')
            ->latest('id')
            ->limit(self::MESSAGES_LIMIT)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Mark the conversation as fully read for the given user.
     * Creates the participant record lazily (e.g., admin opening for the first time).
     */
    public function markRead(ChatConversation $conversation, User $user, ParticipantRole $role): void
    {
        $lastMessage = $conversation->messages()->latest('id')->first();

        if (!$lastMessage) {
            return;
        }

        $participant = $this->ensureParticipant($conversation, $user, $role);
        $participant->update([
            'last_read_message_id' => $lastMessage->id,
            'last_read_at'         => now(),
        ]);
    }

    /**
     * Count unread messages for the given user in this conversation.
     *
     * Only foreign messages are counted (sender_id != user->id). A user's own
     * messages are never considered "unread" for themselves.
     *
     * Note: unread_count excludes soft-deleted messages because the default
     * messages() relation scope omits them.
     *
     * Read-state policy (MVP): read is only advanced through POST /read.
     * GET endpoints (conversation, messages) MUST NOT call markRead().
     */
    public function getUnreadCount(ChatConversation $conversation, User $user): int
    {
        $participant = $conversation->participants()
            ->where('user_id', $user->id)
            ->first();

        return $conversation->messages()
            ->where('id', '>', $participant?->last_read_message_id ?? 0)
            ->where('sender_id', '!=', $user->id)
            ->count();
    }

    /**
     * Ensure a user is recorded as a participant in the conversation.
     * Idempotent: reuses the existing record if already present.
     *
     * Role is IMMUTABLE: $role is only applied when creating a new record.
     * If the participant already exists, their role is preserved unchanged.
     * Domain rule: a user's role in a conversation (CUSTOMER or ADMIN) is
     * fixed at the moment they first join and must not be silently mutated.
     */
    public function ensureParticipant(
        ChatConversation $conversation,
        User $user,
        ParticipantRole $role
    ): ChatParticipant {
        return ChatParticipant::firstOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $user->id],
            ['role' => $role, 'joined_at' => now()]
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function assertNotClosed(ChatConversation $conversation): void
    {
        if ($conversation->status === ConversationStatus::CLOSED) {
            abort(422, 'Диалог закрыт и не принимает новые сообщения.');
        }
    }
}
