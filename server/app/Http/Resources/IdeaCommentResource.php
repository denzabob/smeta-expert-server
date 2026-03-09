<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IdeaCommentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authorNickname = data_get($this->user, 'nickname')
            ?? data_get($this->user, 'name')
            ?? null;

        return [
            'id' => $this->id,
            'idea_id' => $this->idea_id,
            'user_id' => $this->user_id,
            'comment' => $this->comment,
            'text' => $this->comment,
            'author_nickname' => $authorNickname,
            'author_avatar' => data_get($this->user, 'avatar_url') ?? data_get($this->user, 'avatar'),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
