<?php

namespace App\Http\Controllers\Api\Expert;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\ConversationRequest;
use App\Http\Resources\Expert\ConversationResource;
use App\Models\Expert\ExpertConversation;
use App\Models\Expert\ExpertProject;

class ConversationController extends Controller
{
    public function index(ExpertProject $project)
    {
        $this->authorize('view', $project);

        return ConversationResource::collection(
            $project->conversations()
                ->withCount('messages')
                ->withMax('messages as last_message_at', 'created_at')
                ->orderByRaw('GREATEST(COALESCE(last_message_at, expert_conversations.updated_at), expert_conversations.updated_at) DESC')
                ->orderByDesc('expert_conversations.id')
                ->get(),
        );
    }

    public function store(ConversationRequest $request,ExpertProject $project){$this->authorize('update',$project);return response()->json(new ConversationResource($project->conversations()->create($request->validated())),201);}
    public function update(ConversationRequest $request,ExpertConversation $conversation){$this->authorize('update',$conversation->project);$conversation->update($request->validated());return response()->json(new ConversationResource($conversation));}
    public function destroy(ExpertConversation $conversation){$this->authorize('update',$conversation->project);$conversation->delete();return response()->noContent();}
}
