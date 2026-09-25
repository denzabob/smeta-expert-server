<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProjectMaterial;
use App\Models\Expert\ExpertProject;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ExpertStorageService
{
    public function configuredDisk(): string
    {
        $disk = $this->configuredDiskName('expert.storage.disk', 's1');
        if ($disk !== 's1') {
            throw $this->failure('configured_disk', 's1', ExpertStorageException::STORAGE_BACKEND_UNAVAILABLE, null, []);
        }

        return 's1';
    }

    public function cacheDisk(): string
    {
        return $this->configuredDiskName('expert.storage.cache_disk', 'local');
    }

    public function diskForMaterial(ExpertProjectMaterial $material): string
    {
        return $material->storageDisk();
    }

    public function keyForMaterial(ExpertProjectMaterial $material): string
    {
        return $material->storageKey();
    }

    /** @return array{material_id: int, material_public_id: string, project_id: int, project_public_id: string|null, storage_disk: string} */
    public function contextForMaterial(ExpertProjectMaterial $material): array
    {
        return [
            'material_id' => (int) $material->id,
            'material_public_id' => (string) $material->public_id,
            'project_id' => (int) $material->expert_project_id,
            'project_public_id' => $material->relationLoaded('project')
                ? $material->project?->public_id
                : null,
            'storage_disk' => $material->storageDisk(),
        ];
    }

    /** @return array{project_id: int, project_public_id: string, storage_disk?: string} */
    public function contextForProject(ExpertProject $project): array
    {
        return [
            'project_id' => (int) $project->id,
            'project_public_id' => (string) $project->public_id,
        ];
    }

    public function putUploadedFile(string $disk, string $directory, UploadedFile $file, string $name, array $context = []): string
    {
        $this->assertObjectKey($disk, $directory, 'put_file', $context);
        $this->assertNoLocalPersistentMaterialWrite($disk, $directory, 'put_file', $context);
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0") || $name === '.' || $name === '..') {
            throw $this->failure('put_file', $disk, ExpertStorageException::INVALID_OBJECT_KEY, null, $context);
        }

        try {
            $key = $this->filesystem($disk)->putFileAs($directory, $file, $name);
        } catch (Throwable $exception) {
            throw $this->failure('put_file', $disk, ExpertStorageException::WRITE_FAILED, $exception, $context);
        }

        if (! is_string($key) || $key === '') {
            throw $this->failure('put_file', $disk, ExpertStorageException::WRITE_FAILED, null, $context);
        }

        $this->assertObjectKey($disk, $key, 'put_file', $context);

        return $key;
    }

    /** @param resource $stream */
    public function putStream(string $disk, string $key, $stream, array $context = []): void
    {
        $this->assertObjectKey($disk, $key, 'put_stream', $context);
        $this->assertNoLocalPersistentMaterialWrite($disk, $key, 'put_stream', $context);
        if (! is_resource($stream)) {
            throw $this->failure('put_stream', $disk, ExpertStorageException::WRITE_FAILED, null, $context);
        }

        try {
            $stored = $this->filesystem($disk)->put($key, $stream);
        } catch (Throwable $exception) {
            throw $this->failure('put_stream', $disk, ExpertStorageException::WRITE_FAILED, $exception, $context);
        }

        if (! $stored) {
            throw $this->failure('put_stream', $disk, ExpertStorageException::WRITE_FAILED, null, $context);
        }
    }

    public function put(string $disk, string $key, string $contents, array $context = []): void
    {
        $this->assertObjectKey($disk, $key, 'put', $context);
        $this->assertNoLocalPersistentMaterialWrite($disk, $key, 'put', $context);
        try {
            $stored = $this->filesystem($disk)->put($key, $contents);
        } catch (Throwable $exception) {
            throw $this->failure('put', $disk, ExpertStorageException::WRITE_FAILED, $exception, $context);
        }

        if (! $stored) {
            throw $this->failure('put', $disk, ExpertStorageException::WRITE_FAILED, null, $context);
        }
    }

    public function exists(string $disk, string $key, array $context = []): bool
    {
        $this->assertObjectKey($disk, $key, 'exists', $context);
        try {
            return $this->filesystem($disk)->exists($key);
        } catch (Throwable $exception) {
            throw $this->failure('exists', $disk, ExpertStorageException::STORAGE_UNAVAILABLE, $exception, $context);
        }
    }

    public function read(string $disk, string $key, array $context = []): string
    {
        $this->assertExists($disk, $key, 'read', $context);

        try {
            $contents = $this->filesystem($disk)->get($key);
        } catch (Throwable $exception) {
            throw $this->failure('read', $disk, ExpertStorageException::READ_FAILED, $exception, $context);
        }

        if (! is_string($contents)) {
            throw $this->failure('read', $disk, ExpertStorageException::READ_FAILED, null, $context);
        }

        return $contents;
    }

    /** @return resource */
    public function readStream(string $disk, string $key, array $context = [])
    {
        $this->assertExists($disk, $key, 'read_stream', $context);

        try {
            $stream = $this->filesystem($disk)->readStream($key);
        } catch (Throwable $exception) {
            throw $this->failure('read_stream', $disk, ExpertStorageException::READ_FAILED, $exception, $context);
        }

        if (! is_resource($stream)) {
            throw $this->failure('read_stream', $disk, ExpertStorageException::READ_FAILED, null, $context);
        }

        return $stream;
    }

    public function size(string $disk, string $key, array $context = []): int
    {
        $this->assertExists($disk, $key, 'size', $context);

        try {
            return (int) $this->filesystem($disk)->size($key);
        } catch (Throwable $exception) {
            throw $this->failure('size', $disk, ExpertStorageException::READ_FAILED, $exception, $context);
        }
    }

    public function mimeType(string $disk, string $key, array $context = []): string
    {
        $this->assertExists($disk, $key, 'mime_type', $context);

        try {
            $mimeType = $this->filesystem($disk)->mimeType($key);
        } catch (Throwable $exception) {
            throw $this->failure('mime_type', $disk, ExpertStorageException::READ_FAILED, $exception, $context);
        }

        if (! is_string($mimeType) || $mimeType === '') {
            throw $this->failure('mime_type', $disk, ExpertStorageException::READ_FAILED, null, $context);
        }

        return $mimeType;
    }

    public function lastModified(string $disk, string $key, array $context = []): int
    {
        $this->assertExists($disk, $key, 'last_modified', $context);

        try {
            return (int) $this->filesystem($disk)->lastModified($key);
        } catch (Throwable $exception) {
            throw $this->failure('last_modified', $disk, ExpertStorageException::READ_FAILED, $exception, $context);
        }
    }

    public function sha256(string $disk, string $key, array $context = []): string
    {
        $stream = $this->readStream($disk, $key, $context);
        $hashContext = hash_init('sha256');

        try {
            if (hash_update_stream($hashContext, $stream) === false) {
                throw $this->failure('sha256', $disk, ExpertStorageException::READ_FAILED, null, $context);
            }
        } finally {
            fclose($stream);
        }

        return hash_final($hashContext);
    }

    public function delete(string $disk, string $key, array $context = []): void
    {
        $this->assertObjectKey($disk, $key, 'delete', $context);
        if (! $this->exists($disk, $key, $context)) {
            return;
        }

        try {
            $deleted = $this->filesystem($disk)->delete($key);
        } catch (Throwable $exception) {
            throw $this->failure('delete', $disk, ExpertStorageException::DELETE_FAILED, $exception, $context);
        }

        if (! $deleted) {
            throw $this->failure('delete', $disk, ExpertStorageException::DELETE_FAILED, null, $context);
        }
    }

    /** Run a write/read/delete probe through the configured filesystem abstraction. */
    public function healthCheck(string $disk, ?callable $onStep = null): void
    {
        $key = 'expert/health-checks/'.Str::uuid().'.txt';
        $contents = 'Prism Expert storage check '.Str::uuid();
        $writeAttempted = false;
        $step = static function (string $message) use ($onStep): void {
            if ($onStep !== null) {
                $onStep($message);
            }
        };

        try {
            if ($this->exists($disk, $key)) {
                throw $this->failure('health_check', $disk, ExpertStorageException::WRITE_FAILED, null, []);
            }
            $step('Connection: OK');

            $writeAttempted = true;
            $this->put($disk, $key, $contents);
            $step('Write: OK');

            if (! $this->exists($disk, $key)) {
                throw $this->failure('health_check', $disk, ExpertStorageException::FILE_NOT_FOUND, null, []);
            }
            $actualSize = $this->size($disk, $key);
            $step('Exists/stat: OK');
            if ($actualSize !== strlen($contents)) {
                throw $this->failure('health_check', $disk, ExpertStorageException::READ_FAILED, null, []);
            }
            $step('Size verification: OK');
            if ($this->read($disk, $key) !== $contents) {
                throw $this->failure('health_check', $disk, ExpertStorageException::READ_FAILED, null, []);
            }
            $step('Read: OK');

            $this->delete($disk, $key);
            $step('Delete: OK');
            if ($this->exists($disk, $key)) {
                throw $this->failure('health_check', $disk, ExpertStorageException::DELETE_FAILED, null, []);
            }
            $writeAttempted = false;
            $step('Verify deletion: OK');
        } catch (Throwable $exception) {
            if ($writeAttempted) {
                try {
                    $this->delete($disk, $key);
                } catch (Throwable) {
                    // Preserve the original stable storage failure.
                }
            }

            if ($exception instanceof ExpertStorageException) {
                throw $exception;
            }

            throw $this->failure('health_check', $disk, ExpertStorageException::STORAGE_UNAVAILABLE, $exception, []);
        }
    }

    public function deleteDirectory(string $disk, string $key, array $context = []): void
    {
        $this->assertObjectKey($disk, $key, 'delete_directory', $context);
        try {
            $deleted = $this->filesystem($disk)->deleteDirectory($key);
        } catch (Throwable $exception) {
            throw $this->failure('delete_directory', $disk, ExpertStorageException::DELETE_FAILED, $exception, $context);
        }

        if (! $deleted) {
            throw $this->failure('delete_directory', $disk, ExpertStorageException::DELETE_FAILED, null, $context);
        }
    }

    /** @return array{files: int, bytes: int} */
    public function inventoryPrefix(string $disk, string $prefix, array $context = [], ?callable $includeKey = null): array
    {
        $this->assertObjectKey($disk, $prefix, 'inventory_prefix', $context);
        try {
            $filesystem = $this->filesystem($disk);
            $files = $filesystem->allFiles($prefix);
            $bytes = 0;
            $count = 0;
            foreach ($files as $file) {
                if ($includeKey !== null && ! $includeKey((string) $file)) {
                    continue;
                }
                $bytes += (int) $filesystem->size($file);
                ++$count;
            }

            return ['files' => $count, 'bytes' => $bytes];
        } catch (Throwable $exception) {
            throw $this->failure('inventory_prefix', $disk, ExpertStorageException::READ_FAILED, $exception, $context);
        }
    }

    /** Delete a managed prefix and verify that it is empty afterwards. */
    public function deletePrefix(string $disk, string $prefix, array $context = [], ?callable $includeKey = null): array
    {
        $before = $this->inventoryPrefix($disk, $prefix, $context, $includeKey);
        try {
            $filesystem = $this->filesystem($disk);
            foreach ($filesystem->allFiles($prefix) as $key) {
                $key = (string) $key;
                if ($includeKey !== null && ! $includeKey($key)) {
                    continue;
                }
                $this->delete($disk, $key, $context);
            }
        } catch (Throwable $exception) {
            throw $this->failure('delete_prefix', $disk, ExpertStorageException::DELETE_FAILED, $exception, $context);
        }
        $after = $this->inventoryPrefix($disk, $prefix, $context, $includeKey);
        if ($after['files'] !== 0 || $after['bytes'] !== 0) {
            throw $this->failure('delete_prefix', $disk, ExpertStorageException::DELETE_FAILED, null, $context);
        }

        return $before;
    }

    /** @param array<string, string> $headers */
    public function streamResponse(string $disk, string $key, array $headers = [], array $context = []): StreamedResponse
    {
        $stream = $this->readStream($disk, $key, $context);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, Response::HTTP_OK, $headers);
    }

    /** @param array<string, string> $headers */
    public function downloadResponse(string $disk, string $key, string $name, array $headers = [], array $context = []): StreamedResponse
    {
        $stream = $this->readStream($disk, $key, $context);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $name, $headers);
    }

    /**
     * A short-lived local copy for libraries that only accept filesystem paths.
     * The original object is always read through Laravel's configured filesystem.
     */
    public function withTemporaryFile(string $disk, string $key, callable $callback, array $context = []): mixed
    {
        $stream = $this->readStream($disk, $key, $context);

        return $this->withTemporaryFileStream($stream, $callback, $disk, $context);
    }

    /** @template T @param callable(string): T $callback @return T */
    public function withTemporaryFileFromContents(string $contents, callable $callback, array $context = []): mixed
    {
        $stream = @fopen('php://temp', 'w+b');
        if (! is_resource($stream) || fwrite($stream, $contents) !== strlen($contents)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw $this->failure('temporary_file', (string) ($context['storage_disk'] ?? 'local_temp'), ExpertStorageException::READ_FAILED, null, $context);
        }
        rewind($stream);

        return $this->withTemporaryFileStream($stream, $callback, (string) ($context['storage_disk'] ?? 'local_temp'), $context);
    }

    private function withTemporaryFileStream($source, callable $callback, string $disk, array $context): mixed
    {
        $path = @tempnam(sys_get_temp_dir(), 'expert-material-');
        if (! is_string($path)) {
            if (is_resource($source)) {
                fclose($source);
            }
            throw $this->failure('temporary_file', $disk, ExpertStorageException::STORAGE_UNAVAILABLE, null, $context);
        }

        try {
            $target = @fopen($path, 'wb');
            if (! is_resource($target)) {
                throw $this->failure('temporary_file', $disk, ExpertStorageException::WRITE_FAILED, null, $context);
            }
            try {
                if (stream_copy_to_stream($source, $target) === false) {
                    throw $this->failure('temporary_file', $disk, ExpertStorageException::READ_FAILED, null, $context);
                }
            } finally {
                fclose($target);
            }

            return $callback($path);
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            @unlink($path);
        }
    }

    private function assertExists(string $disk, string $key, string $operation, array $context): void
    {
        if (! $this->exists($disk, $key, $context)) {
            throw $this->failure($operation, $disk, ExpertStorageException::FILE_NOT_FOUND, null, $context);
        }
    }

    private function assertObjectKey(string $disk, string $key, string $operation, array $context): void
    {
        if ($key === '' || str_starts_with($key, '/') || str_contains($key, '\\') || str_contains($key, "\0")) {
            throw $this->failure($operation, $disk, ExpertStorageException::INVALID_OBJECT_KEY, null, $context);
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw $this->failure($operation, $disk, ExpertStorageException::INVALID_OBJECT_KEY, null, $context);
            }
        }
    }

    /** Persistent Expert originals may only be written to S1; local remains available for caches. */
    private function assertNoLocalPersistentMaterialWrite(string $disk, string $key, string $operation, array $context): void
    {
        if ($disk !== 'local' || preg_match('#^expert/[^/]+/materials(?:/|$)#', $key) !== 1) {
            return;
        }

        Log::warning('Unexpected local write to persistent Expert material storage.', [
            'operation' => $operation,
            'disk' => $disk,
            'project_id' => isset($context['project_id']) ? (int) $context['project_id'] : null,
            'project_public_id' => isset($context['project_public_id']) ? (string) $context['project_public_id'] : null,
            'error_code' => ExpertStorageException::STORAGE_BACKEND_UNAVAILABLE,
        ]);

        throw new ExpertStorageException(ExpertStorageException::STORAGE_BACKEND_UNAVAILABLE);
    }

    /** @param array<string, mixed> $context */
    private function failure(string $operation, string $disk, string $code, ?Throwable $source, array $context): ExpertStorageException
    {
        if ($source instanceof ExpertStorageException) {
            $failure = $source;
            $code = $source->failureCode;
            $exceptionClass = $source->sourceExceptionClass ?? $source::class;
        } else {
            $failure = new ExpertStorageException($code, $source);
            $exceptionClass = $source === null ? ExpertStorageException::class : $source::class;
        }

        Log::warning('Expert storage operation failed.', [
            'operation' => $operation,
            'disk' => $disk,
            'material_id' => isset($context['material_id']) ? (int) $context['material_id'] : null,
            'material_public_id' => isset($context['material_public_id']) ? (string) $context['material_public_id'] : null,
            'project_id' => isset($context['project_id']) ? (int) $context['project_id'] : null,
            'project_public_id' => isset($context['project_public_id']) ? (string) $context['project_public_id'] : null,
            'cleanup_task_id' => isset($context['cleanup_task_id']) ? (int) $context['cleanup_task_id'] : null,
            'exception_class' => $exceptionClass,
            'error_code' => $code,
        ]);

        return $failure;
    }

    private function filesystem(string $disk)
    {
        if (trim($disk) === '') {
            throw new ExpertStorageException(ExpertStorageException::STORAGE_BACKEND_UNAVAILABLE);
        }

        if ($disk === 's1') {
            foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $required) {
                if (! is_string(config("filesystems.disks.s1.{$required}"))
                    || trim((string) config("filesystems.disks.s1.{$required}")) === '') {
                    throw new ExpertStorageException(ExpertStorageException::STORAGE_BACKEND_UNAVAILABLE);
                }
            }
        }

        try {
            return Storage::disk($disk);
        } catch (Throwable $exception) {
            throw new ExpertStorageException(ExpertStorageException::STORAGE_UNAVAILABLE, $exception);
        }
    }

    private function configuredDiskName(string $configKey, string $fallback): string
    {
        $disk = trim((string) config($configKey, $fallback));

        return $disk !== '' ? $disk : $fallback;
    }
}
