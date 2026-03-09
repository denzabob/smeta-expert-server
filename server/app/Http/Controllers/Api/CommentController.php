<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIdeaCommentRequest;
use App\Http\Resources\IdeaCommentResource;
use App\Models\Idea;
use App\Models\IdeaComment;
use App\Services\CommentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function __construct(private readonly CommentService $commentService)
    {
    }

    public function store(StoreIdeaCommentRequest $request, Idea $idea): JsonResponse
    {
        $comment = $this->commentService->createComment(
            idea: $idea,
            userId: (int) $request->user()->id,
            comment: $request->validated('comment'),
        );

        return response()->json(new IdeaCommentResource($comment), 201);
    }

    public function destroy(Request $request, Idea $idea, IdeaComment $comment): \Illuminate\Http\Response
    {
        if ((int) $comment->idea_id !== (int) $idea->id) {
            abort(404);
        }

        $this->authorize('moderateComments', $idea);

        $this->commentService->deleteComment($comment);

        return response()->noContent();
    }
}
