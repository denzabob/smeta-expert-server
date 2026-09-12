<?php
namespace App\Http\Controllers\Api\Expert;
use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\MaterialUploadRequest;
use App\Http\Resources\Expert\MaterialResource;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Services\Expert\ExpertMaterialService;
use Illuminate\Support\Facades\Storage;
class MaterialController extends Controller
{
    public function __construct(private readonly ExpertMaterialService $service) {}
    public function index(ExpertProject $project){$this->authorize('view',$project);return MaterialResource::collection($project->materials()->latest()->get());}
    public function store(MaterialUploadRequest $request,ExpertProject $project){$this->authorize('update',$project);return response()->json(new MaterialResource($this->service->store($project,(int)$request->user()->id,$request->file('file'))),201);}
    public function show(ExpertProjectMaterial $material){$this->authorize('view',$material->project);return response()->json(new MaterialResource($material));}
    public function content(ExpertProjectMaterial $material){$this->authorize('view',$material->project);abort_unless(str_starts_with((string)$material->mime_type,'image/'),404);abort_unless(Storage::disk('local')->exists($material->storage_path),404);return Storage::disk('local')->response($material->storage_path,$material->original_name,['Content-Type'=>$material->mime_type,'X-Content-Type-Options'=>'nosniff'],'inline');}
    public function download(ExpertProjectMaterial $material){$this->authorize('view',$material->project);abort_unless(Storage::disk('local')->exists($material->storage_path),404);return Storage::disk('local')->download($material->storage_path,$material->original_name,['Content-Type'=>$material->mime_type,'X-Content-Type-Options'=>'nosniff']);}
    public function destroy(ExpertProjectMaterial $material){$this->authorize('update',$material->project);$this->service->delete($material);return response()->noContent();}
}
