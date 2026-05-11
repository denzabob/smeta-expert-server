<?php

namespace App\Http\Controllers\Api;

use App\Enums\Chat\ParticipantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\AdminListConversationsRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\Chat\AdminChatConversationResource;
use App\Http\Resources\Chat\ChatMessageResource;
use App\Models\Chat\ChatConversation;
use App\Models\Chat\ChatMessage;
use App\Services\Chat\SupportChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminChatController extends Controller
{
    public function __construct(
        private SupportChatService $chatService
    ) {}

    public function profile(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'admin_chat_alias' => $request->user()->admin_chat_alias,
            'display_name' => $request->user()->admin_chat_alias ?: $request->user()->name,
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $validated = $request->validate([
            'admin_chat_alias' => ['nullable', 'string', 'max:100'],
        ]);

        $alias = trim((string) ($validated['admin_chat_alias'] ?? ''));
        $request->user()->update([
            'admin_chat_alias' => $alias !== '' ? $alias : null,
        ]);

        return response()->json([
            'admin_chat_alias' => $request->user()->admin_chat_alias,
            'display_name' => $request->user()->admin_chat_alias ?: $request->user()->name,
        ]);
    }

    /**
     * GET /api/admin/chat/conversations
     *
     * Paginated list of support conversations for the admin panel.
     * Filters: status, assigned_admin_id, unassigned, search (user name/email).
     */
    public function index(AdminListConversationsRequest $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $filters = [];

        if ($request->filled('status')) {
            $filters['status'] = $request->validated('status');
        }

        // ?unassigned=1 overrides assigned_admin_id filter
        if ($request->boolean('unassigned')) {
            $filters['assigned_admin_id'] = null;
        } elseif ($request->has('assigned_admin_id')) {
            $filters['assigned_admin_id'] = $request->validated('assigned_admin_id');
        }

        if ($request->filled('search')) {
            $filters['search'] = $request->validated('search');
        }

        $perPage = (int) $request->validated('per_page', 20);
        $paginated = $this->chatService->listForAdmin($filters, $perPage);

        return response()->json([
            'conversations' => AdminChatConversationResource::collection($paginated->items()),
            'pagination'    => [
                'total'        => $paginated->total(),
                'per_page'     => $paginated->perPage(),
                'current_page' => $paginated->currentPage(),
                'last_page'    => $paginated->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/admin/chat/conversations/{conversation}
     *
     * Full conversation detail: participants, messages, creator, assigned admin.
     * Also returns unread count for the current admin.
     */
    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->chatService->getWithDetails($conversation);

        $conversation->unread_count = $this->chatService->getUnreadCount($conversation, $request->user());

        return response()->json([
            'conversation' => new AdminChatConversationResource($conversation),
        ]);
    }

    /**
     * GET /api/admin/chat/conversations/{conversation}/messages
     *
     * Retrieve messages. Supports after_id for polling.
     */
    public function messages(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAdmin($request);

        $afterId = (int) $request->query('after_id', 0);
        $messages = $this->chatService->listMessages($conversation, $afterId);

        return response()->json([
            'messages' => ChatMessageResource::collection($messages),
        ]);
    }

    /**
     * POST /api/admin/chat/conversations/{conversation}/messages
     *
     * Send a message as the current admin.
     * Auto-assigns the admin if the conversation has no assigned admin yet.
     */
    public function sendMessage(SendMessageRequest $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAdmin($request);

        $message = $this->chatService->sendAdminMessage(
            $conversation,
            $request->user(),
            $request->validated('body'),
            $request->hasFile('attachment') ? $request->file('attachment') : null,
        );

        // Clear typing indicator immediately so the user doesn't see a stale state
        \Illuminate\Support\Facades\Cache::forget("chat.typing.admin.{$conversation->id}");

        $message->load(['sender:id,name,admin_chat_alias', 'attachments']);

        return response()->json([
            'message' => new ChatMessageResource($message),
        ], 201);
    }

    /**
     * POST /api/admin/chat/conversations/{conversation}/read
     *
     * Mark conversation as read for the current admin.
     * Creates an admin participant record lazily if not already present.
     */
    public function markRead(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->chatService->markRead($conversation, $request->user(), ParticipantRole::ADMIN);

        return response()->json(['ok' => true]);
    }

    public function deleteMessage(Request $request, ChatConversation $conversation, ChatMessage $message): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->chatService->deleteMessageForAdmin($conversation, $message);

        return response()->json(['ok' => true]);
    }

    public function close(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->chatService->closeConversation($conversation);
        $this->chatService->getWithDetails($conversation);
        $conversation->unread_count = $this->chatService->getUnreadCount($conversation, $request->user());

        return response()->json([
            'conversation' => new AdminChatConversationResource($conversation),
        ]);
    }

    public function reopen(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->chatService->reopenConversation($conversation);
        $this->chatService->getWithDetails($conversation);
        $conversation->unread_count = $this->chatService->getUnreadCount($conversation, $request->user());

        return response()->json([
            'conversation' => new AdminChatConversationResource($conversation),
        ]);
    }

    /**
     * POST /api/admin/chat/conversations/{conversation}/assign
     *
     * Assign the current admin to this conversation.
     * Idempotent: safe to call if already assigned.
     */
    public function assign(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->chatService->assignAdmin($conversation, $request->user());

        $conversation->refresh()->load(['assignedAdmin:id,name,admin_chat_alias']);

        return response()->json([
            'assigned_admin_id' => $conversation->assigned_admin_id,
            'assigned_admin'    => $conversation->assignedAdmin
                ? [
                    'id' => $conversation->assignedAdmin->id,
                    'name' => $conversation->assignedAdmin->name,
                    'admin_chat_alias' => $conversation->assignedAdmin->admin_chat_alias,
                    'display_name' => $conversation->assignedAdmin->admin_chat_alias ?: $conversation->assignedAdmin->name,
                ]
                : null,
        ]);
    }

    /**
     * POST /api/admin/chat/conversations/{conversation}/typing
     *
     * Signal that the admin is currently typing. Stored in cache with 8-second TTL.
     */
    public function reportTyping(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAdmin($request);

        \Illuminate\Support\Facades\Cache::put(
            "chat.typing.admin.{$conversation->id}",
            true,
            now()->addSeconds(8)
        );

        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/admin/chat/conversations/{conversation}/typing-status
     *
     * Check whether the user is currently typing in this conversation.
     */
    public function typingStatus(Request $request, ChatConversation $conversation): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'user_typing' => (bool) \Illuminate\Support\Facades\Cache::get(
                "chat.typing.user.{$conversation->id}", false
            ),
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        if (!$request->user()?->isAdmin()) {
            abort(403, 'Access denied. Admin only.');
        }
    }
}
