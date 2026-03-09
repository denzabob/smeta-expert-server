<?php

namespace App\Services;

use App\Models\Idea;
use App\Models\IdeaComment;

class CommentService
{
    public function createComment(Idea $idea, int $userId, string $comment): IdeaComment
    {
        $created = $idea->comments()->create([
            'user_id' => $userId,
            'comment' => $comment,
            'created_at' => now(),
        ]);

        return $created->load('user');
    }

    public function deleteComment(IdeaComment $comment): void
    {
        $comment->delete();
    }
}
