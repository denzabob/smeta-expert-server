<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatAttachment;
use App\Models\Chat\ChatConversation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatAttachmentController extends Controller
{
    /**
     * GET /api/chat/attachments/{attachment}
     *
     * Stream a chat attachment to the authenticated user.
     * Access is allowed if the requesting user:
     *   - is the creator of the conversation (customer), or
     *   - is an admin.
     * Files are served inline so browsers can display images directly.
     */
    public function download(Request $request, ChatAttachment $attachment): StreamedResponse|Response
    {
        $user = $request->user();
        $message = $attachment->message;
        $conversation = $message->conversation;

        $isOwner  = $conversation->created_by_user_id === $user->id;
        $isAdmin  = $user->isAdmin();

        if (!$isOwner && !$isAdmin) {
            abort(403, 'Нет доступа к этому вложению.');
        }

        $disk = Storage::disk($attachment->disk);

        if (!$disk->exists($attachment->path)) {
            abort(404, 'Файл не найден.');
        }

        // Serve inline for images so the browser can display them without download.
        $disposition = str_starts_with($attachment->mime_type, 'image/')
            ? 'inline'
            : 'attachment';

        return $disk->response(
            $attachment->path,
            $attachment->original_name,
            ['Content-Disposition' => $disposition . '; filename="' . addslashes($attachment->original_name) . '"'],
        );
    }
}
