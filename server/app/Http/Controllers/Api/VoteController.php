<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\VoteIdeaRequest;
use App\Http\Resources\IdeaResource;
use App\Models\Idea;
use App\Services\IdeaService;
use App\Services\VoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoteController extends Controller
{
    public function __construct(
        private readonly VoteService $voteService,
        private readonly IdeaService $ideaService,
    ) {
    }

    public function vote(VoteIdeaRequest $request, Idea $idea): JsonResponse
    {
        $updated = $this->voteService->vote(
            idea: $idea,
            userId: (int) $request->user()->id,
            voteType: $request->validated('vote_type'),
        );

        $detailed = $this->ideaService->getIdeaDetail((int) $updated->id);

        return response()->json(new IdeaResource($detailed));
    }

    public function removeVote(Request $request, Idea $idea): JsonResponse
    {
        $updated = $this->voteService->removeVote($idea, (int) $request->user()->id);
        $detailed = $this->ideaService->getIdeaDetail((int) $updated->id);

        return response()->json(new IdeaResource($detailed));
    }
}
