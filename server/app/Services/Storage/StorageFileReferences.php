<?php

namespace App\Services\Storage;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Explicit business locator map shared by live writes and the deterministic backfill. */
final class StorageFileReferences
{
    public const SOURCES = [
        \App\Models\Expert\ExpertProjectMaterial::class => ['storage_path'],
        \App\Models\PriceListVersion::class => ['file_path'],
        \App\Models\PriceImportSession::class => ['file_path'],
        \App\Models\PriceImport::class => ['file_path'],
        \App\Models\ImportSession::class => ['file_path'],
        \App\Models\GenericEvidenceAsset::class => ['file_path'],
        \App\Models\EvidenceAsset::class => ['file_path'],
        \App\Models\FinishedProductPriceEvidenceAsset::class => ['file_path'],
        \App\Models\EvidenceArtifact::class => ['screenshot_path'],
        \App\Models\MaterialPriceHistory::class => ['screenshot_path', 'snapshot_path'],
        \App\Models\Material::class => ['last_price_screenshot_path'],
        \App\Models\ProjectRevision::class => [],
    ];

    public function owner(Model $model): ?int
    {
        $owner = match ($model::class) {
            \App\Models\Expert\ExpertProjectMaterial::class => $model->project?->user_id,
            \App\Models\ProjectRevision::class => $model->project?->user_id,
            \App\Models\PriceListVersion::class => $model->priceList?->supplier?->user_id,
            \App\Models\GenericEvidenceAsset::class => $model->evidenceRecord?->created_by,
            \App\Models\FinishedProductPriceEvidenceAsset::class => $model->source?->specification?->user_id,
            \App\Models\EvidenceAsset::class => $model->evidenceArtifact ? $this->owner($model->evidenceArtifact) : null,
            \App\Models\EvidenceArtifact::class => $model->revisionRun?->project?->user_id ?? $model->created_by,
            \App\Models\MaterialPriceHistory::class => $model->evidenceArtifact ? $this->owner($model->evidenceArtifact) : ($model->evidenceRecord?->created_by ?? $this->historyOwner($model)),
            default => $model->user_id,
        };
        if ($model instanceof \App\Models\EvidenceArtifact && $model->created_by && $owner && (int) $model->created_by !== (int) $owner) {
            throw new LogicException('Conflicting storage file owners.');
        }
        return $owner ? (int) $owner : null;
    }

    private function historyOwner(Model $model): ?int
    {
        $owners = DB::table('revision_run_items as items')->join('revision_runs as runs', 'runs.id', '=', 'items.revision_run_id')
            ->join('projects', 'projects.id', '=', 'runs.project_id')->where('items.price_history_id', $model->id)
            ->distinct()->pluck('projects.user_id');
        if ($owners->count() > 1) {
            throw new LogicException('Conflicting history screenshot owners.');
        }
        return $owners->isEmpty() ? null : (int) $owners->first();
    }

    public function locators(Model $model): array
    {
        $result = [];
        if ($model instanceof \App\Models\ProjectRevision) {
            $snapshot = json_decode((string) $model->snapshot_json, true, flags: JSON_THROW_ON_ERROR);
            $this->snapshotLocators(is_array($snapshot) ? $snapshot : [], $result);
            return array_values($result);
        }
        foreach (self::SOURCES[$model::class] ?? [] as $column) {
            $path = $model->getAttribute($column);
            if (is_string($path) && $path !== '') {
                $disk = $model->getAttribute('storage_disk');
                $disk = str_starts_with($path, 'smeta/') ? ObjectStorage::DISK : ($disk ?: ($model instanceof \App\Models\Expert\ExpertProjectMaterial ? 'local' : 'public'));
                $result[$disk . ':' . $path] = ['disk' => $disk, 'path' => $path];
            }
        }
        return array_values($result);
    }

    private function snapshotLocators(array $snapshot, array &$result): void
    {
        foreach (['screenshot_path', 'snapshot_path', 'file_path'] as $key) {
            $path = $snapshot[$key] ?? null;
            if (is_string($path) && $path !== '' && !filter_var($path, FILTER_VALIDATE_URL)) {
                $disk = str_starts_with($path, 'smeta/') ? ObjectStorage::DISK : ($snapshot['storage_disk'] ?? 'public');
                $result[$disk . ':' . $path] = ['disk' => $disk, 'path' => $path];
            }
        }
        foreach ($snapshot as $value) {
            if (is_array($value)) { $this->snapshotLocators($value, $result); }
        }
    }

    public function isPlatformLocator(string $path): bool
    {
        return str_starts_with($path, 'smeta/screenshots/parser/');
    }

    /**
     * Legacy public locators in an immutable project snapshot describe the
     * historical report state. They are not account-owned registry objects.
     * Canonical S1-prefixed locators remain owning references and must be
     * covered by storage_files/storage_file_links.
     */
    public function isHistoricalProjectRevisionLocator(Model $model, array $locator): bool
    {
        if (!$model instanceof \App\Models\ProjectRevision) {
            return false;
        }

        $path = ltrim(str_replace('\\', '/', (string) ($locator['path'] ?? '')), '/');

        // An explicit S1 locator is current storage even when its path is
        // malformed. Let coverage report the invalid path instead of hiding
        // a new write-flow defect as historical data.
        if (($locator['disk'] ?? null) === ObjectStorage::DISK) {
            return false;
        }

        return !str_starts_with($path, ObjectStorage::PREFIX . '/')
            && !str_starts_with($path, 'expert/');
    }

    public function isNonPersistentLocator(Model $model, array $locator): bool
    {
        if (!$model instanceof \App\Models\ImportSession && !$model instanceof \App\Models\Material) {
            return false;
        }

        $declaredDisk = $model->getAttribute('storage_disk');
        return $declaredDisk !== ObjectStorage::DISK && !str_starts_with($locator['path'], 'smeta/');
    }

    public function module(Model $model): string
    {
        return $model instanceof \App\Models\Expert\ExpertProjectMaterial ? 'expert' : 'smeta';
    }

    public function isCanonicalPath(string $module, string $path): bool
    {
        return str_starts_with($path, $module . '/');
    }

    public function declaredDisk(Model $model, string $path): ?string
    {
        if ($model instanceof \App\Models\ProjectRevision) {
            $snapshot = json_decode((string) $model->snapshot_json, true, flags: JSON_THROW_ON_ERROR);
            return $this->snapshotDeclaredDisk(is_array($snapshot) ? $snapshot : [], $path);
        }

        $disk = $model->getAttribute('storage_disk');
        return is_string($disk) && trim($disk) !== '' ? trim($disk) : null;
    }

    private function snapshotDeclaredDisk(array $snapshot, string $path): ?string
    {
        foreach (['screenshot_path', 'snapshot_path', 'file_path'] as $key) {
            if (($snapshot[$key] ?? null) === $path) {
                $disk = $snapshot['storage_disk'] ?? null;
                return is_string($disk) && trim($disk) !== '' ? trim($disk) : null;
            }
        }

        foreach ($snapshot as $value) {
            if (is_array($value)) {
                $disk = $this->snapshotDeclaredDisk($value, $path);
                if ($disk !== null) {
                    return $disk;
                }
            }
        }

        return null;
    }

    public function sync(Model $model): void
    {
        $usage = app(StorageUsageService::class);
        $module = $model instanceof \App\Models\Expert\ExpertProjectMaterial ? 'expert' : 'smeta';
        $retained = [];
        foreach ($this->locators($model) as $locator) {
            $file = DB::table('storage_files')->where($locator)->where('status', 'active')->first();
            if (!$file) {
                continue; // System/parser sources are not account-owned uploads.
            }
            $owner = $this->owner($model);
            if ($owner !== null && $owner !== (int) $file->user_id) {
                throw new LogicException('A storage locator cannot belong to two accounts.');
            }
            $usage->link((int) $file->id, $module, $model->getTable(), $model->getKey());
            $retained[] = (int) $file->id;
        }
        $stale = DB::table('storage_file_links')->where('module', $module)->where('source_type', $model->getTable())
            ->where('source_id', $model->getKey())->whereNotIn('storage_file_id', $retained)->pluck('storage_file_id');
        foreach ($stale as $id) {
            $removed = $usage->unlink($module, $model->getTable(), $model->getKey(), (int) $id);
            if ($removed !== []) {
                DB::afterCommit(fn () => \App\Jobs\DeleteAccountStorageFiles::enqueue());
            }
        }
    }
}
