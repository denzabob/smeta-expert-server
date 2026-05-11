<?php

namespace App\Http\Resources\Chat;

use App\Enums\Chat\ParticipantRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'conversation_id'     => $this->conversation_id,
            'sender_id'           => $this->sender_id,
            'sender_role'         => $this->sender_role instanceof \BackedEnum
                ? $this->sender_role->value
                : $this->sender_role,
            'type'                => $this->type instanceof \BackedEnum
                ? $this->type->value
                : $this->type,
            'body'                => $this->body,
            'meta_json'           => $this->meta_json,
            'is_mine'             => $this->sender_id !== null && $this->sender_id === $request->user()?->id,
            'sender_display_name' => $this->whenLoaded('sender', function () {
                $senderRole = $this->sender_role instanceof \BackedEnum
                    ? $this->sender_role->value
                    : $this->sender_role;

                if ($senderRole === ParticipantRole::ADMIN->value) {
                    return $this->sender?->admin_chat_alias ?: $this->sender?->name;
                }

                return $this->sender?->name;
            }),
            'attachments'         => ChatAttachmentResource::collection(
                $this->whenLoaded('attachments', fn () => $this->attachments)
            ),
            'created_at'          => optional($this->created_at)->toIso8601String(),
        ];
    }
}
