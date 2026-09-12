<?php
namespace App\Http\Controllers\Api\Expert;
use App\Http\Controllers\Controller; use App\Http\Requests\Expert\ResearchObjectRequest; use App\Http\Resources\Expert\ResearchObjectResource; use App\Models\Expert\ExpertProject; use App\Models\Expert\ExpertResearchObject;
class ResearchObjectController extends Controller
{
    public function index(ExpertProject $project){$this->authorize('view',$project);return ResearchObjectResource::collection($project->researchObjects);}
    public function store(ResearchObjectRequest $request,ExpertProject $project){$this->authorize('update',$project);return response()->json(new ResearchObjectResource($project->researchObjects()->create($request->validated())),201);}
    public function update(ResearchObjectRequest $request,ExpertResearchObject $researchObject){$this->authorize('update',$researchObject->project);$researchObject->update($request->validated());return response()->json(new ResearchObjectResource($researchObject));}
    public function destroy(ExpertResearchObject $researchObject){$this->authorize('update',$researchObject->project);$researchObject->delete();return response()->noContent();}
}
