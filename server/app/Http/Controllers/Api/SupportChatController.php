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

        $message->load(['sender:id,name', 'attachments']);

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
}
