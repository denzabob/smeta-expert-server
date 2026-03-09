<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class IdeaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $authorNickname = data_get($this->user, 'nickname')
            ?? data_get($this->user, 'name')
            ?? null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'author_nickname' => $authorNickname,
            'user_id' => $this->user_id,
            'votes_up' => (int) $this->votes_up,
            'votes_down' => (int) $this->votes_down,
            'score' => (int) $this->votes_up - (int) $this->votes_down,
            'views' => (int) $this->views,
            'comments_count' => (int) ($this->comments_count ?? $this->comments()->count()),
            'status' => $this->status,
            'tags' => $this->whenLoaded('tags', function () {
                return $this->tags
                    ->map(fn ($tag) => [
                        'id' => $tag->id,
                        'name' => $tag->name,
                    ])
                    ->values();
            }, []),
            'attachments' => $this->whenLoaded('attachments', function () {
                return $this->attachments
                    ->map(fn ($attachment) => [
                        'id' => $attachment->id,
                        'file_path' => $attachment->file_path,
                        'mime_type' => $attachment->mime_type,
                        'url' => Storage::disk('public')->url($attachment->file_path),
                        'created_at' => optional($attachment->created_at)->toIso8601String(),
                    ])
                    ->values();
            }, []),
            'comments' => IdeaCommentResource::collection($this->whenLoaded('comments')),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
