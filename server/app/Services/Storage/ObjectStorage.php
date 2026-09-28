<?php

namespace App\Services\Storage;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Shared private object-storage operations used by persistent Smeta files.
 * New objects are always written to S1 under the isolated `smeta/` prefix.
 */
class ObjectStorage
{
    public const DISK = 's1';
    public const PREFIX = 'smeta';

    public function storeUploaded(string $collection, UploadedFile $file, ?int $ownerId = null): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $key = $this->newKey($collection, $ownerId, $extension);
        $stream = fopen($file->getRealPath(), 'rb');

        if ($stream === false) {
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE);
        }

        try {
            $this->writeStream(self::DISK, $key, $stream);
            return $key;
        } finally {
            fclose($stream);
        }
    }

    public function storeBytes(string $collection, string $bytes, string $extension = '', ?int $ownerId = null): string
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE);
        }

        try {
            fwrite($stream, $bytes);
            rewind($stream);
            $key = $this->newKey($collection, $ownerId, $extension);
            $this->writeStream(self::DISK, $key, $stream);
            return $key;
        } finally {
            fclose($stream);
        }
    }

    public function importFile(string $collection, string $sourcePath, ?int $ownerId = null): string
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new ObjectStorageException(ObjectStorageException::FILE_NOT_FOUND);
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $stream = fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw new ObjectStorageException(ObjectStorageException::FILE_NOT_FOUND);
        }

        try {
            $key = $this->newKey($collection, $ownerId, $extension);
            $this->writeStream(self::DISK, $key, $stream);
            return $key;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Promote a parser-created local file to S1 before its locator is persisted.
     * This is for the current processing result only; it does not scan or migrate legacy files.
     */
    public function ingestGeneratedPath(string $collection, ?string $sourcePath, ?int $ownerId = null): ?string
    {
        $sourcePath = trim((string) $sourcePath);
        if ($sourcePath === '' || filter_var($sourcePath, FILTER_VALIDATE_URL)) {
            return null;
        }

        if (str_starts_with($sourcePath, self::PREFIX . '/')) {
            if (!str_starts_with($sourcePath, self::PREFIX . '/screenshots/parser/')) {
                return null;
            }

            if (!$this->exists(self::DISK, $sourcePath)) {
                throw new ObjectStorageException(ObjectStorageException::FILE_NOT_FOUND);
            }

            return $sourcePath;
        }

        $publicDisk = Storage::disk('public');
        $publicRoot = realpath($publicDisk->path(''));
        $resolvedSource = realpath($sourcePath);
        if ($resolvedSource && $publicRoot
            && str_starts_with($resolvedSource, rtrim($publicRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            $sourcePath = ltrim(substr($resolvedSource, strlen(rtrim($publicRoot, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
        }

        if (!str_starts_with($sourcePath, 'screenshots/') || str_contains($sourcePath, '..')) {
            return null;
        }

        if ($publicDisk->exists($sourcePath)) {
            try {
                return $this->copy('public', $sourcePath, $collection, $ownerId);
            } finally {
                $publicDisk->delete($sourcePath);
            }
        }

        return null;
    }

    public function copy(string $sourceDisk, string $sourcePath, string $collection, ?int $ownerId = null): string
    {
        try {
            $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
            if (!is_resource($stream)) {
                throw new ObjectStorageException(ObjectStorageException::FILE_NOT_FOUND);
            }

            try {
                $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
                $key = $this->newKey($collection, $ownerId, $extension);
                $this->writeStream(self::DISK, $key, $stream);
                return $key;
            } finally {
                fclose($stream);
            }
        } catch (ObjectStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('copy');
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, $exception);
        }
    }

    public function exists(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->exists($path);
        } catch (Throwable $exception) {
            $this->logFailure('exists');
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, $exception);
        }
    }

    /**
     * Resolve a persisted locator's disk while preserving older local/public rows.
     * S1 keys are namespaced under `smeta/`; for older rows without disk metadata,
     * inspect both legacy disks instead of assuming that every locator is public.
     */
    public function resolveDisk(?string $disk, string $path, string $legacyDefault = 'public'): string
    {
        $normalizedPath = ltrim(str_replace('\\', '/', trim($path)), '/');
        if (str_starts_with($normalizedPath, self::PREFIX . '/')) {
            return self::DISK;
        }

        $disk = trim((string) $disk);
        if ($disk !== '') {
            return $disk;
        }

        foreach (array_unique([$legacyDefault, 'public', 'local']) as $candidate) {
            try {
                if (Storage::disk($candidate)->exists($path)) {
                    return $candidate;
                }
            } catch (Throwable) {
                // Continue checking configured legacy disks; callers still verify the resolved locator.
            }
        }

        return $legacyDefault;
    }

    public function size(string $disk, string $path): int
    {
        try {
            return (int) Storage::disk($disk)->size($path);
        } catch (Throwable $exception) {
            $this->logFailure('stat');
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, $exception);
        }
    }

    public function delete(string $disk, string $path): bool
    {
        try {
            return Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            $this->logFailure('delete');
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, $exception);
        }
    }

    /**
     * Materialize a private object only for a synchronous parser/PDF callback.
     * The temporary file is removed even if the callback throws.
     */
    public function withTemporaryFile(string $disk, string $path, string $extension, Closure $callback): mixed
    {
        $temporaryPath = $this->materializeTemporaryFile($disk, $path, $extension);

        try {
            return $callback($temporaryPath);
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * Create a temporary local copy whose lifecycle is owned by the caller.
     */
    public function materializeTemporaryFile(
        string $disk,
        string $path,
        string $extension,
        ?string $temporaryDirectory = null,
    ): string
    {
        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (Throwable $exception) {
            $this->logFailure('read');
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, $exception);
        }

        if (!is_resource($stream)) {
            throw new ObjectStorageException(ObjectStorageException::FILE_NOT_FOUND);
        }

        $temporaryDirectory ??= sys_get_temp_dir();
        if (!is_dir($temporaryDirectory) || !is_writable($temporaryDirectory)) {
            fclose($stream);
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE);
        }

        $basePath = tempnam($temporaryDirectory, 'smeta-');
        if ($basePath === false) {
            fclose($stream);
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE);
        }

        $extension = strtolower((string) preg_replace('/[^a-z0-9]/', '', $extension));
        $temporaryPath = $extension !== '' ? $basePath . '.' . $extension : $basePath;
        if ($temporaryPath !== $basePath && !rename($basePath, $temporaryPath)) {
            fclose($stream);
            @unlink($basePath);
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE);
        }

        try {
            $target = fopen($temporaryPath, 'wb');
            if ($target === false) {
                throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE);
            }

            try {
                if (stream_copy_to_stream($stream, $target) === false) {
                    throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE);
                }
            } finally {
                fclose($target);
            }

            return $temporaryPath;
        } catch (ObjectStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('read');
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, $exception);
        } finally {
            fclose($stream);
        }
    }

    /** @return list<string> */
    public function listSmetaObjects(): array
    {
        try {
            return Storage::disk(self::DISK)->allFiles(self::PREFIX);
        } catch (Throwable $exception) {
            $this->logFailure('list');
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, $exception);
        }
    }

    public function downloadResponse(
        string $disk,
        string $path,
        string $filename,
        ?string $mimeType = null,
        bool $inline = false,
    ): StreamedResponse {
        if (!$this->exists($disk, $path)) {
            throw new ObjectStorageException(ObjectStorageException::FILE_NOT_FOUND);
        }

        $filename = basename(str_replace('\\', '/', $filename));
        $fallbackFilename = preg_replace('/[^A-Za-z0-9._-]/', '_', Str::ascii($filename)) ?: 'download';
        $headers = array_filter([
            'Content-Type' => $mimeType,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                $inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
                $fallbackFilename,
            ),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);

        return response()->stream(function () use ($disk, $path): void {
            try {
                $stream = Storage::disk($disk)->readStream($path);
                if (!is_resource($stream)) {
                    return;
                }

                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            } catch (Throwable) {
                $this->logFailure('download');
            }
        }, 200, $headers);
    }

    private function newKey(string $collection, ?int $ownerId, string $extension): string
    {
        $collection = trim($collection, '/');
        if ($collection === '' || str_contains($collection, '..')) {
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE);
        }

        $extension = strtolower((string) preg_replace('/[^a-z0-9]/', '', $extension));
        $ownerPrefix = $ownerId !== null ? $ownerId . '/' : '';
        $suffix = $extension !== '' ? '.' . $extension : '';

        return self::PREFIX . '/' . $collection . '/' . $ownerPrefix . Str::uuid() . $suffix;
    }

    /** @param resource $stream */
    private function writeStream(string $disk, string $path, $stream): void
    {
        try {
            $written = Storage::disk($disk)->writeStream($path, $stream);
            if ($written === false) {
                throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, objectDisk: $disk, objectPath: $path);
            }
        } catch (ObjectStorageException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logFailure('write');
            throw new ObjectStorageException(ObjectStorageException::BACKEND_UNAVAILABLE, $exception, $disk, $path);
        }
    }

    private function logFailure(string $operation): void
    {
        Log::warning('Smeta object storage operation failed', [
            'disk' => self::DISK,
            'operation' => $operation,
            'code' => ObjectStorageException::BACKEND_UNAVAILABLE,
        ]);
    }

}
