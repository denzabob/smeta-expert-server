<?php
namespace App\Services\Expert;
use App\Models\Expert\ExpertProject;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
class ExpertProjectService
{
    public function __construct(private readonly ExpertStorageCleanupService $storageCleanup) {}

    public function create(int $userId, array $data): ExpertProject
    {
        return DB::transaction(function () use ($userId, $data) {
            $initial = Arr::pull($data, 'initial_research_object');
            $project = ExpertProject::create(['user_id'=>$userId] + $data);
            if ($initial) $project->researchObjects()->create($initial);
            return $project->load('researchObjects')->loadCount(['researchObjects','conversations','materials','findings']);
        });
    }
    public function delete(ExpertProject $project): void
    {
        $directory="expert/{$project->public_id}";

        if (! $this->storageCleanup->journalIsAvailable()) {
            DB::transaction(fn () => $project->delete());
            $this->storageCleanup->deleteBestEffort('directory', $directory);

            return;
        }

        $task = DB::transaction(function () use ($directory, $project) {
            $task = $this->storageCleanup->scheduleDirectory($directory);
            $project->delete();

            return $task;
        });

        $this->storageCleanup->attempt($task);
    }
}
