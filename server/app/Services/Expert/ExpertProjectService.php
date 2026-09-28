<?php
namespace App\Services\Expert;
use App\Models\Expert\ExpertProject;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
class ExpertProjectService
{
    public function __construct(
        private readonly ExpertStorageCleanupService $storageCleanup,
        private readonly ExpertStorageService $storage,
        private readonly ExpertStorageUsageService $storageUsage,
    ) {}

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
        $journalAvailable = $this->storageCleanup->journalIsAvailable();
        $cleanup = DB::transaction(function () use ($directory, $project, $journalAvailable): array {
            $target = ExpertProject::query()->lockForUpdate()->findOrFail($project->id);
            $disks = $this->cleanupDisks($target);
            $context = $this->storage->contextForProject($target);

            foreach ($target->materials()->get() as $material) {
                app(\App\Services\Storage\StorageUsageService::class)->unlink('expert', $material->getTable(), (int) $material->id);
            }

            // Originals are removed individually by the generic retry job: a shared
            // object must never be removed by a project-directory cleanup.
            $cacheDisks = array_values(array_diff($disks, ['s1']));
            $tasks = $journalAvailable
                ? array_map(fn (string $disk) => $this->storageCleanup->scheduleDirectory($directory, $disk), $cacheDisks)
                : [];
            $target->delete();

            DB::afterCommit(fn () => \App\Jobs\DeleteAccountStorageFiles::enqueue());
            return ['tasks' => $tasks, 'disks' => $cacheDisks, 'context' => $context];
        }, 3);

        if ($journalAvailable) {
            foreach ($cleanup['tasks'] as $task) {
                $this->storageCleanup->attempt($task, $cleanup['context']);
            }

            return;
        }

        foreach ($cleanup['disks'] as $disk) {
            $this->storageCleanup->deleteBestEffort('directory', $directory, $disk, $cleanup['context']);
        }
    }

    /** @return list<string> */
    private function cleanupDisks(ExpertProject $project): array
    {
        $disks = $project->materials()->get(['storage_disk'])
            ->map(fn ($material): string => $this->storage->diskForMaterial($material))
            ->all();
        $disks[] = $this->storage->cacheDisk();
        $disks[] = 'local';

        return array_values(array_unique($disks));
    }
}
