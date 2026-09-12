<?php
namespace App\Http\Controllers\Api\Expert;
use App\Http\Controllers\Controller; use App\Http\Requests\Expert\ProjectRequest; use App\Http\Resources\Expert\ProjectResource; use App\Models\Expert\ExpertProject; use App\Services\Expert\ExpertProjectService; use Illuminate\Http\Request;
class ProjectController extends Controller
{
    public function __construct(private readonly ExpertProjectService $service) {}
    public function index(Request $request) { $this->authorize('viewAny',ExpertProject::class); return ProjectResource::collection(ExpertProject::where('user_id',$request->user()->id)->withCount(['researchObjects','conversations','materials','findings'])->latest('updated_at')->get()); }
    public function store(ProjectRequest $request) { $this->authorize('create',ExpertProject::class); return response()->json(new ProjectResource($this->service->create((int)$request->user()->id,$request->validated())),201); }
    public function show(ExpertProject $project) { $this->authorize('view',$project); return response()->json(new ProjectResource($project->load(['researchObjects','conversations'=>fn($q)=>$q->withCount('messages'),'materials','findings.researchObject','findings.materials'])->loadCount(['researchObjects','conversations','materials','findings']))); }
    public function update(ProjectRequest $request,ExpertProject $project) { $this->authorize('update',$project); $data=$request->validated(); unset($data['initial_research_object']); $project->update($data); return response()->json(new ProjectResource($project->refresh())); }
    public function destroy(ExpertProject $project) { $this->authorize('delete',$project); $this->service->delete($project); return response()->noContent(); }
}
