<?php
namespace App\Services\Expert;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
class ExpertMaterialService
{
    public function __construct(private readonly ExpertStorageCleanupService $storageCleanup) {}

    public function store(ExpertProject $project, int $userId, UploadedFile $file): ExpertProjectMaterial
    {
        $extension=strtolower($file->getClientOriginalExtension());
        $storedName=(string) Str::uuid().'.'.$extension;
        $path="expert/{$project->public_id}/materials/{$storedName}";
        $storedPath=Storage::disk('local')->putFileAs("expert/{$project->public_id}/materials", $file, $storedName);
        if ($storedPath === false) {
            throw new \RuntimeException('Unable to store expert material file.');
        }
        try {
            return ExpertProjectMaterial::create(['expert_project_id'=>$project->id,'uploaded_by'=>$userId,'original_name'=>$file->getClientOriginalName(),'storage_path'=>$path,'mime_type'=>$file->getMimeType() ?: 'application/octet-stream','extension'=>$extension,'size'=>$file->getSize(),'category'=>str_starts_with((string)$file->getMimeType(),'image/')?'image':($extension==='xlsx'?'spreadsheet':'document'),'status'=>'uploaded']);
        } catch (\Throwable $e) { Storage::disk('local')->delete($path); throw $e; }
    }
    public function delete(ExpertProjectMaterial $material): void
    {
        if ($material->findings()->exists()) throw new ConflictHttpException('Material is linked to a finding.');
        $path=$material->storage_path;

        if (! $this->storageCleanup->journalIsAvailable()) {
            DB::transaction(fn () => $material->delete());
            $this->storageCleanup->deleteBestEffort('file', $path);

            return;
        }

        $task = DB::transaction(function () use ($material, $path) {
            $task = $this->storageCleanup->scheduleFile($path);
            $material->delete();

            return $task;
        });

        $this->storageCleanup->attempt($task);
    }
}
