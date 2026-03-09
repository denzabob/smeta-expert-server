<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIdeaRequest;
use App\Http\Requests\UpdateIdeaStatusRequest;
use App\Http\Resources\IdeaResource;
use App\Models\Idea;
use App\Services\IdeaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IdeaController extends Controller
{
    public function __construct(private readonly IdeaService $ideaService)
    {
    }

    public function store(StoreIdeaRequest $request): JsonResponse
    {
        $idea = $this->ideaService->createIdea(
            userId: (int) $request->user()->id,
            payload: $request->validated(),
            attachments: $request->file('attachments', []),
        );

        return response()->json(new IdeaResource($idea), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in(Idea::STATUSES)],
            'tag' => 'sometimes|string|max:128',
            'search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:new,top,hot',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $ideas = $this->ideaService->listIdeas($validated);

        return response()->json([
            'data' => IdeaResource::collection($ideas->items()),
            'meta' => [
                'current_page' => $ideas->currentPage(),
                'last_page' => $ideas->lastPage(),
                'per_page' => $ideas->perPage(),
                'total' => $ideas->total(),
            ],
        ]);
    }

    public function show(Idea $idea): JsonResponse
    {
        $idea->increment('views');

        $detailedIdea = $this->ideaService->getIdeaDetail((int) $idea->id);

        return response()->json(new IdeaResource($detailedIdea));
    }

    public function updateStatus(UpdateIdeaStatusRequest $request, Idea $idea): JsonResponse
    {
        $this->authorize('updateStatus', $idea);

        $updated = $this->ideaService->updateStatus($idea, $request->validated('status'));

        return response()->json([
            'message' => 'Idea status updated.',
            'data' => new IdeaResource($updated),
        ]);
    }

    public function destroy(Idea $idea): \Illuminate\Http\Response
    {
        $this->authorize('delete', $idea);

        $this->ideaService->deleteIdea($idea);

        return response()->noContent();
    }
}
