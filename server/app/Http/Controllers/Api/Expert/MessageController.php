<?php
namespace App\Http\Controllers\Api\Expert;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\MessageRequest;
use App\Http\Resources\Expert\MessageResource;
use App\Models\Expert\ExpertConversation;
class MessageController extends Controller
{
    public function index(ExpertConversation $conversation){$this->authorize('view',$conversation->project);return MessageResource::collection($conversation->messages);}
    public function store(MessageRequest $request,ExpertConversation $conversation){$this->authorize('update',$conversation->project);$message=$conversation->messages()->create(['role'=>'user','content'=>$request->validated('content')]);return response()->json(new MessageResource($message),201);}
}
