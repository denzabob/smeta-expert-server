<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\Chat\ChatConversationResource;
use App\Http\Resources\Chat\ChatMessageResource;
use App\Models\Chat\ChatConversation;
use App\Services\Chat\SupportChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportChatController extends Controller
{
    public function __construct(
        private SupportChatService $chatService
    ) {}

    /**
     * GET /api/support-chat/conversation
     *
     * Returns the user's active support conversation.
     * Creates a new one if none exists.
     * Includes the latest messages and unread count.
     */
    public function conversation(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversation = $this->chatService->getOrCreateForUser($user);

        $messages = $this->chatService->listMessages($conversation);
        $conversation->setRelation('messages', $messages);
        $conversation->load('participants.user:id,name');
        $conversation->unread_count = $this->chatService->getUnreadCount($conversation, $user);

        return response()->json([
            'conversation' => new ChatConversationResource($conversation),
        ]);
    }

    /**
     * GET /api/support-chat/conversations/{conversation}/messages
     *
     * Retrieve messages for the conversation.
     * Supports after_id for polling: returns only messages with id > after_id.
     */
    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $afterId = (int) $request->query('after_id', 0);
        $messages = $this->chatService->listMessages($conversation, $afterId);

        return response()->json([
            'messages' => ChatMessageResource::collection($messages),
            'conversation_status' => $conversation->status instanceof \BackedEnum
                ? $conversation->status->value
                : $conversation->status,
        ]);
    }

    /**
     * POST /api/support-chat/conversations/{conversation}/messages
     *
     * Send a message as the authenticated user.
     */
    public function sendMessage(SendMessageRequest $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorize('sendMessage', $conversation);

        $message = $this->chatService->sendUserMessage(
            $conversation,
            $request->user(),
            $request->validated('body'),
            $request->hasFile('attachment') ? $request->file('attachment') : null,
        );

        // Clear typing indicator immediately so the other party doesn't see a stale state
        \Illuminate\Support\Facades\Cache::forget("chat.typing.user.{$conversation->id}");

        $message->load(['sender:id,name,admin_chat_alias', 'attachments']);

        return response()->json([
            'message' => new ChatMessageResource($message),
        ], 201);
    }

    /**
     * POST /api/support-chat/conversations/{conversation}/read
     *
     * Mark all messages in the conversation as read for the current user.
     */
    public function markRead(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorize('markRead', $conversation);

        $this->chatService->markRead(
            $conversation,
            $request->user(),
            \App\Enums\Chat\ParticipantRole::CUSTOMER
        );

        return response()->json(['ok' => true]);
    }

    public function reopen(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        $this->chatService->reopenConversation($conversation);

        $messages = $this->chatService->listMessages($conversation);
        $conversation->setRelation('messages', $messages);
        $conversation->load('participants.user:id,name');
        $conversation->unread_count = $this->chatService->getUnreadCount($conversation, $request->user());

        return response()->json([
            'conversation' => new ChatConversationResource($conversation),
        ]);
    }

    /**
     * POST /api/support-chat/conversations/{conversation}/typing
     *
     * Signal that the user is currently typing. Stored in cache with 8-second TTL.
     */
    public function reportTyping(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        \Illuminate\Support\Facades\Cache::put(
            "chat.typing.user.{$conversation->id}",
            true,
            now()->addSeconds(8)
        );

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/support-chat/conversations/{conversation}/typing-status
     *
     * Check whether the admin is currently typing in this conversation.
     */
    public function typingStatus(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        return response()->json([
            'admin_typing' => (bool) \Illuminate\Support\Facades\Cache::get(
                "chat.typing.admin.{$conversation->id}", false
            ),
        ]);
    }
}
