<?php

namespace App\Services\Expert;

use App\Models\Expert\ExpertStorageCleanupTask;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class ExpertStorageCleanupService
{
    public function __construct(private readonly ExpertStorageService $storage) {}

    public function scheduleFile(string $path, string $disk = 'local'): ExpertStorageCleanupTask
    {
        return $this->schedule('file', $path, $disk);
    }

    public function scheduleDirectory(string $path, string $disk = 'local'): ExpertStorageCleanupTask
    {
        return $this->schedule('directory', $path, $disk);
    }

    public function journalIsAvailable(): bool
    {
        return Schema::hasTable('expert_storage_cleanup_tasks');
    }

    public function deleteBestEffort(string $kind, string $path, string $disk = 'local', array $context = []): void
    {
        try {
            $this->assertManagedPath($path);
            $kind === 'directory'
                ? $this->storage->deleteDirectory($disk, $path, $context)
                : $this->storage->delete($disk, $path, $context);
        } catch (Throwable $exception) {
            Log::warning('Expert storage cleanup failed before the cleanup journal migration was available.', [
                'operation' => $kind === 'directory' ? 'delete_directory' : 'delete',
                'disk' => $disk,
                ...$context,
                'kind' => $kind,
                'exception_class' => $exception instanceof ExpertStorageException
                    ? ($exception->sourceExceptionClass ?? $exception::class)
                    : $exception::class,
                'error_code' => $exception instanceof ExpertStorageException
                    ? $exception->failureCode
                    : 'CLEANUP_FAILED',
            ]);
        }
    }

    public function attempt(ExpertStorageCleanupTask $task, array $context = []): bool
    {
        $context['cleanup_task_id'] = (int) $task->id;

        try {
            $this->assertManagedPath($task->path);

            $task->kind === 'directory'
                ? $this->storage->deleteDirectory($task->disk, $task->path, $context)
                : $this->storage->delete($task->disk, $task->path, $context);
            $task->delete();

            return true;
        } catch (Throwable $exception) {
            $this->markFailed(
                $task,
                $exception instanceof ExpertStorageException ? $exception->failureCode : 'CLEANUP_FAILED',
                $exception instanceof ExpertStorageException
                    ? ($exception->sourceExceptionClass ?? $exception::class)
                    : $exception::class,
            );
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

    private function schedule(string $kind, string $path, string $disk): ExpertStorageCleanupTask
    {
        $this->assertManagedPath($path);
        if ($disk === '' || strlen($disk) > 32) {
            throw new RuntimeException('Refusing to schedule cleanup for an invalid storage disk.');
        }

        return ExpertStorageCleanupTask::query()->firstOrCreate(
            ['disk' => $disk, 'path' => $path],
            [
                'kind' => $kind,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ],
        );
    }

    private function markFailed(ExpertStorageCleanupTask $task, string $errorCode, string $exceptionClass): void
    {
        $attempts = $task->attempts + 1;
        $delays = config('expert.storage_cleanup_retry_delays_seconds', [60]);
        $delayIndex = min($attempts - 1, count($delays) - 1);
        $delay = max(1, (int) ($delays[$delayIndex] ?? 60));

        $task->forceFill([
            'attempts' => $attempts,
            'last_error' => mb_substr($errorCode, 0, 65535),
            'next_attempt_at' => now()->addSeconds($delay),
        ])->save();

        Log::warning('Expert storage cleanup was deferred for retry.', [
            'task_id' => $task->id,
            'operation' => $task->kind === 'directory' ? 'delete_directory' : 'delete',
            'disk' => $task->disk,
            'kind' => $task->kind,
            'attempts' => $attempts,
            'exception_class' => $exceptionClass,
            'error_code' => $errorCode,
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
