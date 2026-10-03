<?php

namespace App\Services\Storage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only coverage checks for the account storage registry.
 *
 * The business locator list is deliberately owned by StorageFileReferences;
 * this service only compares those locators with registry rows and links.
 */
final class StorageCoverageAudit
{
    public const UNREGISTERED = 'UNREGISTERED_PERSISTENT_FILE';
    public const ORPHAN = 'ORPHAN_STORAGE_FILE';
    public const INVALID_DISK = 'INVALID_PERSISTENT_DISK';
    public const INVALID_PATH = 'INVALID_PERSISTENT_PATH';

    public function __construct(private readonly StorageFileReferences $references) {}

    /**
     * @return array{unregistered:int, orphan:int, invalid_disk:int, invalid_path:int, invalid_locators:int, historical:int, issues:array<int,array<string,mixed>>}
     */
    public function audit(?int $userId = null): array
    {
        $expected = [];
        $issues = [];
        $historical = 0;
        $hasBusinessSourceTable = false;

        foreach (array_keys(StorageFileReferences::SOURCES) as $class) {
            $prototype = new $class();
            if (!Schema::hasTable($prototype->getTable())) {
                continue;
            }
            $hasBusinessSourceTable = true;

            $class::query()->orderBy('id')->chunkById(200, function ($rows) use (&$expected, &$issues, &$historical, $userId): void {
                foreach ($rows as $model) {
                    $owner = $this->references->owner($model);
                    if ($owner === null || ($userId !== null && $owner !== $userId)) {
                        continue;
                    }

                    foreach ($this->references->locators($model) as $locator) {
                        if ($this->references->isPlatformLocator($locator['path'])) {
                            continue;
                        }

                        if ($this->references->isHistoricalProjectRevisionLocator($model, $locator)) {
                            $historical++;
                            continue;
                        }

                        $module = $this->references->module($model);
                        if ($this->references->isNonPersistentLocator($model, $locator)) {
                            continue;
                        }

                        $issueContext = $this->context($model, $module, $locator, $owner);
                        $declaredDisk = $this->references->declaredDisk($model, $locator['path']);
                        $pathValid = $this->references->isCanonicalPath($module, $locator['path']);
                        $diskValid = $declaredDisk === null || trim((string) $declaredDisk) === ''
                            ? true
                            : $declaredDisk === ObjectStorage::DISK;

                        if (!$diskValid) {
                            $issues[] = $issueContext + [
                                'code' => self::INVALID_DISK,
                                'disk' => $declaredDisk,
                            ];
                        }
                        if (!$pathValid) {
                            $issues[] = $issueContext + [
                                'code' => self::INVALID_PATH,
                            ];
                        }
                        if (!$diskValid || !$pathValid) {
                            continue;
                        }

                        $key = ObjectStorage::DISK . ':' . $locator['path'];
                        $linkKey = $module . '|' . $model->getTable() . '|' . $model->getKey();
                        $expected[$key] ??= [
                            'disk' => ObjectStorage::DISK,
                            'path' => $locator['path'],
                            'module' => $module,
                            'owner' => $owner,
                            'public_id' => $model->getAttribute('public_id'),
                            'size_bytes' => $this->modelSize($model),
                            'links' => [],
                        ];
                        $expected[$key]['links'][$linkKey] = [
                            'module' => $module,
                            'source_type' => $model->getTable(),
                            'source_id' => (string) $model->getKey(),
                        ];
                    }
                }
            });
        }

        // Isolated storage-service tests can intentionally create only the
        // registry schema. Without any business source table there is no
        // canonical set against which coverage can be evaluated.
        if (!$hasBusinessSourceTable) {
            return [
                'unregistered' => 0,
                'orphan' => 0,
                'invalid_disk' => 0,
                'invalid_path' => 0,
                'invalid_locators' => 0,
                'historical' => 0,
                'issues' => [],
            ];
        }

        $registryQuery = DB::table('storage_files')->whereIn('module', ['expert', 'smeta']);
        if ($userId !== null) {
            $registryQuery->where('user_id', $userId);
        }
        $registry = $registryQuery->get([
            'id', 'user_id', 'module', 'disk', 'path', 'size_bytes', 'status', 'deleted_at',
        ]);
        $registryIds = $registry->pluck('id')->all();
        $linksByFile = $registryIds === []
            ? collect()
            : DB::table('storage_file_links')->whereIn('storage_file_id', $registryIds)->get()
                ->groupBy('storage_file_id');
        $seenKeys = [];

        foreach ($registry as $file) {
            if ($this->isPendingDeletion($file) || $this->references->isPlatformLocator((string) $file->path)) {
                continue;
            }

            $module = (string) $file->module;
            $path = (string) $file->path;
            if ($module === 'expert' || $module === 'smeta') {
                if ((string) $file->disk !== ObjectStorage::DISK) {
                    $issues[] = $this->registryContext($file) + ['code' => self::INVALID_DISK];
                }
                if (!$this->references->isCanonicalPath($module, $path)) {
                    $issues[] = $this->registryContext($file) + ['code' => self::INVALID_PATH];
                }
            }

            $key = $file->disk . ':' . $path;
            $seenKeys[$key] = true;
            $expectedLinks = $expected[$key]['links'] ?? [];
            $actualLinks = $linksByFile->get($file->id, collect());
            $hasValidLink = $actualLinks->contains(function ($link) use ($expectedLinks): bool {
                $key = $link->module . '|' . $link->source_type . '|' . $link->source_id;
                return isset($expectedLinks[$key]);
            });
            if (!$hasValidLink) {
                $issues[] = $this->registryContext($file) + ['code' => self::ORPHAN];
            }
        }

        foreach ($expected as $key => $businessFile) {
            if (!isset($seenKeys[$key])) {
                $representative = array_values($businessFile['links'])[0];
                $issues[] = [
                    'code' => self::UNREGISTERED,
                    'module' => $businessFile['module'],
                    'source' => $representative['source_type'],
                    'source_id' => $representative['source_id'],
                    'public_id' => $businessFile['public_id'],
                    'user_id' => $businessFile['owner'],
                    'disk' => $businessFile['disk'],
                    'path' => $businessFile['path'],
                    'size_bytes' => $businessFile['size_bytes'],
                ];
            }
        }

        $counts = [
            'unregistered' => $this->countCode($issues, self::UNREGISTERED),
            'orphan' => $this->countCode($issues, self::ORPHAN),
            'invalid_disk' => $this->countCode($issues, self::INVALID_DISK),
            'invalid_path' => $this->countCode($issues, self::INVALID_PATH),
        ];
        $counts['invalid_locators'] = $counts['invalid_disk'] + $counts['invalid_path'];

        return $counts + ['historical' => $historical, 'issues' => $issues];
    }

    /** @return array<string,mixed> */
    private function context(Model $model, string $module, array $locator, int $owner): array
    {
        return [
            'module' => $module,
            'source' => $model->getTable(),
            'source_id' => (string) $model->getKey(),
            'public_id' => $model->getAttribute('public_id'),
            'user_id' => $owner,
            'disk' => $locator['disk'],
            'path' => $locator['path'],
            'size_bytes' => $this->modelSize($model),
        ];
    }

    /** @return array<string,mixed> */
    private function registryContext(object $file): array
    {
        return [
            'module' => $file->module,
            'source' => 'storage_files',
            'source_id' => (string) $file->id,
            'user_id' => $file->user_id,
            'disk' => $file->disk,
            'path' => $file->path,
            'size_bytes' => (int) $file->size_bytes,
        ];
    }

    private function isPendingDeletion(object $file): bool
    {
        return $file->deleted_at !== null || in_array((string) $file->status, ['deleting', 'deleted'], true);
    }

    private function modelSize(Model $model): ?int
    {
        foreach (['size_bytes', 'file_size', 'size'] as $column) {
            $value = $model->getAttribute($column);
            if ($value !== null) {
                return (int) $value;
            }
        }
        return null;
    }

    private function countCode(array $issues, string $code): int
    {
        return count(array_filter($issues, static fn (array $issue): bool => $issue['code'] === $code));
    }
}
