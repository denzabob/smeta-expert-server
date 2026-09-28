<?php
namespace App\Services\Expert;
use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
class ExpertMaterialService
{
    public function __construct(
        private readonly ExpertStorageService $storage,
        private readonly ExpertStorageCleanupService $storageCleanup,
        private readonly \App\Services\Storage\StorageUsageService $storageUsage,
        private readonly ExpertMaterialThumbnailService $thumbnails,
        private readonly ExpertPdfOcrCache $ocrCache,
    ) {}

    public function store(ExpertProject $project, int $userId, UploadedFile $file): ExpertProjectMaterial
    {
        $extension=strtolower($file->getClientOriginalExtension());
        $storedName=(string) Str::uuid().'.'.$extension;
        $disk = $this->storage->configuredDisk();
        $context = $this->storage->contextForProject($project);
        $directory="expert/{$project->public_id}/materials";
        $storedKey = "{$directory}/{$storedName}";
        $uploadedSize = $file->getSize();
        if (! is_int($uploadedSize) || $uploadedSize < 0) {
            throw new \RuntimeException('Could not determine the uploaded file size.');
        }
        $size = $uploadedSize;
        $reservationId = (string) Str::uuid();
        $reservationCreated = false;
        $putAttempted = false;
        try {
            DB::transaction(function () use ($project, $userId, $reservationId, $size): void {
                $target = ExpertProject::query()->lockForUpdate()->findOrFail($project->id);
                $owner = User::query()->findOrFail((int) $target->user_id);
                $this->storageUsage->reserve((int) $owner->id, 'expert', $size, $reservationId,
                    (int) config('expert.storage_reservation_ttl_minutes', 60));
            }, 3);
            $reservationCreated = true;

            // Persist the object only after quota has been reserved. There is no
            // local fallback: the configured primary disk must be S1.
            $putAttempted = true;
            $storedKey = $this->storage->putUploadedFile($disk, $directory, $file, $storedName, $context);
            $size = $this->storage->size($disk, $storedKey, $context);

            return DB::transaction(function () use ($project, $userId, $file, $disk, $storedKey, $extension, $size, $reservationId): ExpertProjectMaterial {
                $target = ExpertProject::query()->lockForUpdate()->findOrFail($project->id);
                $owner = User::query()->findOrFail((int) $target->user_id);
                $this->storageUsage->finalize($reservationId, [
                    'disk' => $disk, 'path' => $storedKey, 'purpose' => 'expert_material',
                    'size_bytes' => $size, 'mime_type' => $file->getMimeType(),
                    'original_filename' => $file->getClientOriginalName(), 'billable' => true,
                ]);

                return ExpertProjectMaterial::create([
                    'expert_project_id' => $target->id,
                    'uploaded_by' => $userId,
                    'original_name' => $file->getClientOriginalName(),
                    'storage_disk' => $disk,
                    'storage_path' => $storedKey,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                    'extension' => $extension,
                    'size' => $size,
                    'category' => str_starts_with((string) $file->getMimeType(), 'image/') ? 'image' : ($extension === 'xlsx' ? 'spreadsheet' : 'document'),
                    'status' => 'uploaded',
                ]);
            }, 3);
        } catch (\Throwable $e) {
            if ($putAttempted) {
                $this->compensateUploadObject($disk, $storedKey, $context);
            }
            if ($reservationCreated) {
                try {
                    $this->storageUsage->release($reservationId);
                } catch (\Throwable $releaseException) {
                    Log::warning('Expert storage upload reservation release failed.', [
                        'operation' => 'release_reservation',
                        'project_id' => (int) $project->id,
                        'exception_class' => $releaseException::class,
                        'error_code' => 'STORAGE_RESERVATION_RELEASE_FAILED',
                    ]);
                }
            }

            throw $e;
        }
    }

    /** @param array<string, mixed> $context */
    private function compensateUploadObject(string $disk, string $key, array $context): void
    {
        try {
            $this->storage->delete($disk, $key, $context);
        } catch (\Throwable $cleanupException) {
            try {
                if ($this->storageCleanup->journalIsAvailable()) {
                    $task = $this->storageCleanup->scheduleFile($key, $disk);
                    $this->storageCleanup->attempt($task, $context);
                } else {
                    Log::warning('Expert material upload compensation could not be journaled.', [
                        'operation' => 'upload_compensation',
                        'disk' => $disk,
                        ...$context,
                        'exception_class' => $cleanupException instanceof ExpertStorageException
                            ? ($cleanupException->sourceExceptionClass ?? $cleanupException::class)
                            : $cleanupException::class,
                        'error_code' => $cleanupException instanceof ExpertStorageException
                            ? $cleanupException->failureCode
                            : 'CLEANUP_JOURNAL_UNAVAILABLE',
                    ]);
                }
            } catch (\Throwable $journalException) {
                Log::warning('Expert material upload compensation could not be journaled.', [
                    'operation' => 'upload_compensation',
                    'disk' => $disk,
                    ...$context,
                    'exception_class' => $journalException::class,
                    'error_code' => $journalException instanceof ExpertStorageException
                        ? $journalException->failureCode
                        : 'CLEANUP_JOURNAL_FAILED',
                ]);
            }
        }
    }
    public function delete(ExpertProjectMaterial $material, bool $allowLinkedFindings = false): void
    {
        $journalAvailable = $this->storageCleanup->journalIsAvailable();
        $cleanup = DB::transaction(function () use ($material, $journalAvailable, $allowLinkedFindings): ?array {
            $project = ExpertProject::query()
                ->lockForUpdate()
                ->find($material->expert_project_id);
            $target = ExpertProjectMaterial::query()
                ->whereKey($material->getKey())
                ->lockForUpdate()
                ->first();

            if ($project === null || $target === null) {
                return null;
            }

            if ($target->findings()->exists()) {
                if (! $allowLinkedFindings) {
                    throw new ConflictHttpException('Material is linked to a finding.');
                }
            }
            if ($allowLinkedFindings) {
                DB::table('expert_finding_material')->where('expert_project_material_id', $target->id)->delete();
                if (Schema::hasTable('expert_message_materials')) {
                    DB::table('expert_message_materials')->where('expert_project_material_id', $target->id)->delete();
                }
            }

            $disk = $this->storage->diskForMaterial($target);
            $key = $this->storage->keyForMaterial($target);
            $cacheDisk = $this->storage->cacheDisk();
            $context = $this->storage->contextForMaterial($target);

            $this->storageUsage->unlink('expert', $target->getTable(), (int) $target->id);
            $shared = \Illuminate\Support\Facades\DB::table('storage_files')
                ->where('disk', $disk)->where('path', $key)->where('status', 'active')->exists();

            if ($journalAvailable) {
                $tasks = [
                    $this->storageCleanup->scheduleDirectory($this->thumbnails->cacheDirectory($target), $cacheDisk),
                    $this->storageCleanup->scheduleDirectory($this->ocrCache->cacheDirectory($target), $cacheDisk),
                ];
                if (!$shared) {
                    $tasks[] = $this->storageCleanup->scheduleFile($key, $disk);
                }
                $target->delete();

                return ['journal' => true, 'tasks' => $tasks, 'context' => $context];
            }

            $target->delete();

            return [
                'journal' => false,
                'context' => $context,
                'items' => [
                    ...($shared ? [] : [['kind' => 'file', 'path' => $key, 'disk' => $disk]]),
                    ['kind' => 'directory', 'path' => $this->thumbnails->cacheDirectory($target), 'disk' => $cacheDisk],
                    ['kind' => 'directory', 'path' => $this->ocrCache->cacheDirectory($target), 'disk' => $cacheDisk],
                ],
            ];
        }, 3);

        if ($cleanup === null) {
            return;
        }

        if ($cleanup['journal']) {
            foreach ($cleanup['tasks'] as $task) {
                $this->storageCleanup->attempt($task, $cleanup['context']);
            }

            return;
        }

        foreach ($cleanup['items'] as $item) {
            $this->storageCleanup->deleteBestEffort($item['kind'], $item['path'], $item['disk'], $cleanup['context']);
        }
    }
}
