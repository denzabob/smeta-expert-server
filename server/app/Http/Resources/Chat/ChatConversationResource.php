<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'status'            => $this->status instanceof \BackedEnum
                ? $this->status->value
                : $this->status,
            'subject'           => $this->subject,
            'assigned_admin_id' => $this->assigned_admin_id,
            'last_message_at'   => optional($this->last_message_at)->toIso8601String(),
            'unread_count'      => (int) ($this->unread_count ?? 0),
            'participants'      => $this->whenLoaded('participants', function () {
                return $this->participants->map(fn ($p) => [
                    'id'      => $p->id,
                    'user_id' => $p->user_id,
                    'role'    => $p->role instanceof \BackedEnum ? $p->role->value : $p->role,
                    'name'    => optional($p->user)->name,
                ])->values();
            }, []),
            'messages'          => ChatMessageResource::collection($this->whenLoaded('messages')),
            'created_at'        => optional($this->created_at)->toIso8601String(),
        ];
    }
}
