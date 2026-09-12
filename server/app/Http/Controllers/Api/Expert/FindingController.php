<?php
namespace App\Http\Controllers\Api\Expert;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\FindingRequest;
use App\Http\Resources\Expert\FindingResource;
use App\Models\Expert\ExpertFinding;
use App\Models\Expert\ExpertProject;
use App\Services\Expert\ExpertFindingService;
class FindingController extends Controller
{
    public function __construct(private readonly ExpertFindingService $service) {}
    public function index(ExpertProject $project){$this->authorize('view',$project);return FindingResource::collection($project->findings()->with(['researchObject','materials'])->latest()->get());}
    public function store(FindingRequest $request,ExpertProject $project){$this->authorize('update',$project);return response()->json(new FindingResource($this->service->create($project,(int)$request->user()->id,$request->validated())),201);}
    public function update(FindingRequest $request,ExpertFinding $finding){$this->authorize('update',$finding->project);return response()->json(new FindingResource($this->service->update($finding,$request->validated())));}
    public function destroy(ExpertFinding $finding){$this->authorize('update',$finding->project);$finding->delete();return response()->noContent();}
}
