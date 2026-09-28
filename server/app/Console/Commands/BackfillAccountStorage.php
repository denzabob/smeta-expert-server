<?php

namespace App\Console\Commands;

use App\Services\Storage\ObjectStorage;
use App\Services\Storage\StorageFileReferences;
use App\Services\Storage\StorageUsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class BackfillAccountStorage extends Command
{
    protected $signature = 'storage:backfill-registry {--dry-run : Validate owners, objects and sizes without changing the registry}';
    protected $description = 'Register existing Expert/Smeta S1 objects without moving physical files';

    public function handle(StorageFileReferences $references, ObjectStorage $objects, StorageUsageService $usage): int
    {
        try {
            foreach (['storage_upload_reservations', 'expert_storage_upload_reservations'] as $table) {
                if (Schema::hasTable($table) && DB::table($table)->where('status', 'reserved')->where('expires_at', '>', now())->exists()) {
                    $this->error('ACTIVE_STORAGE_RESERVATIONS: завершите загрузки до backfill.');
                    return self::FAILURE;
                }
            }
            $files = [];
            foreach (array_keys(StorageFileReferences::SOURCES) as $class) {
                $prototype = new $class();
                if (!Schema::hasTable($prototype->getTable())) {
                    continue;
                }
                $class::query()->orderBy('id')->chunkById(200, function ($rows) use (&$files, $references, $objects) {
                    foreach ($rows as $model) {
                        foreach ($references->locators($model) as $locator) {
                            if (str_starts_with($locator['path'], 'smeta/screenshots/parser/')) {
                                continue; // Platform catalog source, even inside an account revision snapshot.
                            }
                            // A Smeta locator is an S1 object even if a legacy write
                            // left storage_disk empty on the business row.
                            $disk = str_starts_with($locator['path'], 'smeta/')
                                ? ObjectStorage::DISK
                                : (string) ($locator['disk'] ?? '');
                            if ($disk !== ObjectStorage::DISK) {
                                if ($model instanceof \App\Models\ImportSession || $model instanceof \App\Models\Material) {
                                    continue; // TTL processing sources and global catalog cache pointers.
                                }
                                throw new \RuntimeException('UNEXPECTED_LEGACY_PERSISTENT_LOCATOR');
                            }
                            $owner = $references->owner($model);
                            if ($owner === null) {
                                if ($model instanceof \App\Models\Material) {
                                    continue; // A global catalog cache pointer is not an account owner.
                                }
                                throw new \RuntimeException('STORAGE_OWNER_UNRESOLVED');
                            }
                            $key = $disk . ':' . $locator['path'];
                            $module = $model instanceof \App\Models\Expert\ExpertProjectMaterial ? 'expert' : 'smeta';
                            $physicalModule = str_starts_with($locator['path'], 'expert/') ? 'expert' : 'smeta';
                            $declaredSize = $model->getAttribute('size_bytes') ?? $model->getAttribute('file_size')
                                ?? ($module === 'expert' ? $model->getAttribute('size') : null);
                            if (!isset($files[$key])) {
                                if (!$objects->exists($disk, $locator['path'])) {
                                    throw new \RuntimeException('STORAGE_OBJECT_MISSING');
                                }
                                $actualSize = $objects->size($disk, $locator['path']);
                                $files[$key] = [
                                    'user_id' => $owner, 'module' => $physicalModule, 'links' => [],
                                    'metadata' => ['disk' => $disk, 'path' => $locator['path']] + [
                                        'purpose' => $model->getTable(), 'size_bytes' => $actualSize,
                                        'original_filename' => $model->original_filename ?? $model->original_name,
                                        'mime_type' => $model->mime_type ?? Storage::disk($disk)->mimeType($locator['path']),
                                        'billable' => !($model instanceof \App\Models\ImportSession),
                                    ],
                                ];
                            }
                            $file = &$files[$key];
                            if ($file['user_id'] !== $owner
                                || ($declaredSize !== null && (int) $declaredSize !== $file['metadata']['size_bytes'])) {
                                throw new \RuntimeException('STORAGE_OBJECT_METADATA_CONFLICT');
                            }
                            if ($model->mime_type) {
                                $file['metadata']['mime_type'] = $model->mime_type;
                            }
                            $file['links'][] = ['module' => $module, 'source_type' => $model->getTable(), 'source_id' => $model->getKey()];
                            unset($file);
                        }
                    }
                });
            }
            // All metadata is validated before the first mutation. Run in maintenance
            // mode during rollout so business locators cannot change between phases.
            if (!$this->option('dry-run')) {
                DB::transaction(function () use ($files, $usage) {
                    foreach ($files as $file) {
                        $usage->register($file['user_id'], $file['module'], $file['metadata'], $file['links']);
                    }
                }, 3);
            }
            $this->info('Storage registry objects validated: ' . count($files));
            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Account storage registry backfill failed.', ['exception' => $e]);
            $safeCodes = ['STORAGE_OWNER_UNRESOLVED', 'STORAGE_OBJECT_MISSING', 'STORAGE_OBJECT_METADATA_CONFLICT', 'UNEXPECTED_LEGACY_PERSISTENT_LOCATOR'];
            $this->error(in_array($e->getMessage(), $safeCodes, true) ? $e->getMessage() : 'STORAGE_BACKFILL_FAILED');
            return self::FAILURE;
        }
    }
}
