<?php

namespace App\Services\Expert;

use App\Models\Expert\ExpertStorageCleanupTask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ExpertStorageCleanupService
{
    public function scheduleFile(string $path): ExpertStorageCleanupTask
    {
        return $this->schedule('file', $path);
    }

    public function scheduleDirectory(string $path): ExpertStorageCleanupTask
    {
        return $this->schedule('directory', $path);
    }

    public function journalIsAvailable(): bool
    {
        return Schema::hasTable('expert_storage_cleanup_tasks');
    }

    public function deleteBestEffort(string $kind, string $path): void
    {
        try {
            $this->assertManagedPath($path);
            $disk = Storage::disk('local');

            if ($disk->exists($path) && ! ($kind === 'directory'
                ? $disk->deleteDirectory($path)
                : $disk->delete($path))) {
                Log::warning('Expert storage cleanup failed before the cleanup journal migration was available.', [
                    'path' => $path,
                    'kind' => $kind,
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('Expert storage cleanup threw before the cleanup journal migration was available.', [
                'path' => $path,
                'kind' => $kind,
                'exception' => $exception::class,
            ]);
        }
    }

    public function attempt(ExpertStorageCleanupTask $task): bool
    {
        try {
            $this->assertManagedPath($task->path);

            $disk = Storage::disk($task->disk);
            $removed = ! $disk->exists($task->path)
                || ($task->kind === 'directory'
                    ? $disk->deleteDirectory($task->path)
                    : $disk->delete($task->path));

            if ($removed) {
                $task->delete();

                return true;
            }

            $this->markFailed($task, 'Storage driver returned false.');
        } catch (Throwable $exception) {
            $this->markFailed($task, $exception->getMessage());
        }

        return false;
    }

    /**
     * @return array{processed: int, removed: int, failed: int}
     */
    public function retryDue(int $limit = 100): array
    {
        $processed = 0;
        $removed = 0;

        $tasks = ExpertStorageCleanupTask::query()
            ->where('next_attempt_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($tasks as $task) {
            ++$processed;

            if ($this->attempt($task)) {
                ++$removed;
            }
        }

        return [
            'processed' => $processed,
            'removed' => $removed,
            'failed' => $processed - $removed,
        ];
    }

    private function schedule(string $kind, string $path): ExpertStorageCleanupTask
    {
        $this->assertManagedPath($path);

        return ExpertStorageCleanupTask::query()->firstOrCreate(
            ['disk' => 'local', 'path' => $path],
            [
                'kind' => $kind,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ],
        );
    }

    private function markFailed(ExpertStorageCleanupTask $task, string $error): void
    {
        $attempts = $task->attempts + 1;
        $delays = config('expert.storage_cleanup_retry_delays_seconds', [60]);
        $delayIndex = min($attempts - 1, count($delays) - 1);
        $delay = max(1, (int) ($delays[$delayIndex] ?? 60));

        $task->forceFill([
            'attempts' => $attempts,
            'last_error' => mb_substr($error, 0, 65535),
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();

        Log::warning('Expert storage cleanup was deferred for retry.', [
            'task_id' => $task->id,
            'disk' => $task->disk,
            'path' => $task->path,
            'kind' => $task->kind,
            'attempts' => $attempts,
        ]);
    }

    private function assertManagedPath(string $path): void
    {
        if (! str_starts_with($path, 'expert/')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || str_contains($path, "\0")) {
            throw new RuntimeException('Refusing to clean a path outside expert storage.');
        }
    }
}
