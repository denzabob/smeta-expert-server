<?php

namespace App\Http\Resources\Expert;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['public_id' => $this->public_id, 'role' => $this->role, 'content' => $this->content, 'metadata' => $this->metadata, 'attachments' => $this->role === 'user' ? MessageMaterialResource::collection($this->attachments) : [], 'feedback' => $this->whenLoaded('feedback', fn () => $this->feedback->first()?->only(['rating', 'reason_code', 'comment'])), 'created_at' => $this->created_at?->toIso8601String(), 'updated_at' => $this->updated_at?->toIso8601String()];
    }
}
