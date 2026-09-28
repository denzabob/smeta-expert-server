<?php

namespace App\Services\Storage;

use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Account lifecycle orchestration; ObjectStorage remains the physical adapter. */
final class AccountFileStorage
{
    public function __construct(private ObjectStorage $objects, private StorageUsageService $usage) {}

    public function prepareUploaded(string $collection, UploadedFile $file, int $ownerId, bool $billable = true): PreparedStorageUpload
    {
        return $this->prepare($ownerId, 'smeta', $collection, (int) $file->getSize(),
            $file->getMimeType(), $file->getClientOriginalName(), $billable,
            fn () => $this->objects->storeUploaded($collection, $file, $ownerId));
    }

    public function prepareUploads(string $collection, array $files, int $ownerId): array
    {
        $uploads = [];
        try {
            foreach ($files as $file) {
                $uploads[] = $this->prepareUploaded($collection, $file, $ownerId);
            }
            return $uploads;
        } catch (Throwable $e) {
            $this->discard($uploads);
            throw $e;
        }
    }

    public function prepareFile(string $collection, string $source, int $ownerId, bool $billable = true): PreparedStorageUpload
    {
        return $this->prepare($ownerId, 'smeta', $collection, (int) filesize($source),
            mime_content_type($source) ?: null, basename($source), $billable,
            fn () => $this->objects->importFile($collection, $source, $ownerId));
    }

    private function prepare(int $userId, string $module, string $purpose, int $size, ?string $mime, ?string $name, bool $billable, Closure $write): PreparedStorageUpload
    {
        $reservation = $billable ? $this->usage->reserve($userId, $module, $size) : null;
        $path = null;
        try {
            $path = $write();
            $actual = $this->objects->size(ObjectStorage::DISK, $path);
            return new PreparedStorageUpload($userId, $module, [
                'disk' => ObjectStorage::DISK, 'path' => $path, 'purpose' => $purpose,
                'size_bytes' => $actual, 'mime_type' => $mime, 'original_filename' => $name,
                'billable' => $billable,
            ], $reservation);
        } catch (Throwable $e) {
            if ($path === null && $e instanceof ObjectStorageException) {
                $path = $e->objectPath;
            }
            if ($path !== null) {
                $this->deleteCompensation(ObjectStorage::DISK, $path, $userId, $module);
            }
            if ($reservation !== null) {
                $this->releaseReservation($reservation);
            }
            throw $e;
        }
    }

    /** S1 writes have completed; registry, references and counters commit together. */
    public function commit(array $uploads, Closure $businessWrite): mixed
    {
        try {
            return DB::transaction(function () use ($uploads, $businessWrite) {
                $ids = [];
                foreach ($uploads as $upload) {
                    if ($upload->reservationId !== null) {
                        $ids[] = $this->usage->finalize($upload->reservationId, $upload->metadata);
                    } else {
                        $ids[] = $this->usage->register($upload->userId, $upload->module, $upload->metadata);
                    }
                }
                $result = $businessWrite();
                // A dedup race may reuse an existing business record instead.
                // Newly prepared objects without any link must not remain billable.
                foreach ($ids as $id) {
                    if (!DB::table('storage_file_links')->where('storage_file_id', $id)->exists()) {
                        $file = DB::table('storage_files')->where('id', $id)->first();
                        DB::table('storage_files')->where('id', $id)->update(['status' => 'deleting', 'updated_at' => now()]);
                        $this->usage->recalculate((int) $file->user_id);
                        DB::afterCommit(fn () => \App\Jobs\DeleteAccountStorageFiles::enqueue());
                    }
                }
                return $result;
            });
        } catch (Throwable $e) {
            $this->discard($uploads);
            throw $e;
        }
    }

    public function upload(string $collection, UploadedFile $file, int $ownerId, Closure $businessWrite, bool $billable = true): mixed
    {
        $upload = $this->prepareUploaded($collection, $file, $ownerId, $billable);
        return $this->commit([$upload], fn () => $businessWrite($upload->path()));
    }

    public function discard(array $uploads): void
    {
        foreach ($uploads as $upload) {
            $this->deleteCompensation($upload->metadata['disk'], $upload->path(), $upload->userId, $upload->module);
            if ($upload->reservationId !== null) {
                $this->releaseReservation($upload->reservationId);
            }
        }
    }

    private function deleteCompensation(string $disk, string $path, int $userId, string $module): void
    {
        try {
            if (!$this->objects->delete($disk, $path)) {
                throw new \RuntimeException('Physical compensation failed.');
            }
        } catch (Throwable $e) {
            // Persist a retry rather than silently accepting an orphan object.
            DB::table('storage_files')->insertOrIgnore([
                'public_id' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $userId,
                'module' => $module, 'purpose' => 'upload_compensation', 'disk' => $disk, 'path' => $path,
                'size_bytes' => 0, 'category' => 'file', 'billable' => false, 'status' => 'deleting',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Log::error('Account storage upload compensation failed.', ['exception_class' => $e::class]);
        }
    }

    private function releaseReservation(string $id): void
    {
        try {
            $this->usage->release($id);
        } catch (Throwable $e) {
            Log::error('Account storage reservation release failed.', ['reservation_id' => $id, 'exception_class' => $e::class]);
            // Preserve the original normalized failure; expiry retries once DB recovers.
        }
    }
}
