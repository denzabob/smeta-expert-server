<?php

declare(strict_types=1);

namespace App\Services\Expert;

use App\Models\Expert\ExpertProjectMaterial;
use App\Models\Expert\ExpertStorageMigration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ExpertStorageMigrationService
{
    private const TARGET_DISK = 's1';

    public function __construct(
        private readonly ExpertStorageService $storage,
        private readonly ExpertStorageCleanupService $cleanup,
    ) {}

    /**
     * Read-only plan. It never creates journal rows or writes to S1.
     *
     * @param array{user_id?: int, project_id?: int, material_id?: int} $filters
     * @return array<string, mixed>
     */
    public function plan(array $filters): array
    {
        if (! Schema::hasTable('expert_project_materials')) {
            throw new MigrationStepException('MATERIAL_TABLE_UNAVAILABLE');
        }

        $summary = [
            'materials' => 0,
            'files' => 0,
            'total_size' => 0,
            'already_s1' => 0,
            'legacy_local' => 0,
            'missing_local' => 0,
            'size_mismatch' => 0,
            'hash_missing' => 0,
            'conflicts' => 0,
            'source_check_failed' => 0,
            'target_check_failed' => 0,
            'target_check_available' => $this->s1Configured(),
        ];
        $users = [];

        $this->scopedMaterials($filters)->orderBy('id')->chunkById(100, function ($materials) use (&$summary, &$users): void {
            foreach ($materials as $material) {
                ++$summary['materials'];
                ++$summary['files'];
                $size = max(0, (int) $material->size);
                $summary['total_size'] += $size;
                $userId = (int) $material->project->user_id;
                $users[$userId] ??= ['files' => 0, 'size' => 0];
                ++$users[$userId]['files'];
                $users[$userId]['size'] += $size;

                if ($material->storageDisk() === self::TARGET_DISK) {
                    ++$summary['already_s1'];
                    continue;
                }
                if ($material->storageDisk() !== 'local') {
                    continue;
                }

                ++$summary['legacy_local'];
                if ($this->trustedIdentityHash($material) === null) {
                    ++$summary['hash_missing'];
                }
                try {
                    if (! $this->storage->exists('local', $material->storageKey(), $this->storage->contextForMaterial($material))) {
                        ++$summary['missing_local'];
                        continue;
                    }
                    if ($this->storage->size('local', $material->storageKey()) !== $size) {
                        ++$summary['size_mismatch'];
                    }
                } catch (Throwable) {
                    ++$summary['source_check_failed'];
                    continue;
                }
                if ($summary['target_check_available']) {
                    try {
                        $targetKey = $this->targetKey($material);
                        if ($this->storage->exists(self::TARGET_DISK, $targetKey)) {
                            $sourceHash = $this->storage->sha256('local', $material->storageKey());
                            $targetSize = $this->storage->size(self::TARGET_DISK, $targetKey);
                            $targetHash = $this->storage->sha256(self::TARGET_DISK, $targetKey);
                            if ($targetSize !== $size || ! hash_equals($sourceHash, $targetHash)) {
                                ++$summary['conflicts'];
                            }
                        }
                    } catch (Throwable) {
                        ++$summary['target_check_failed'];
                    }
                }
            }
        });

        ksort($users);

        return ['summary' => $summary, 'users' => $users];
    }

    /**
     * @param array{user_id?: int, project_id?: int, material_id?: int} $filters
     * @param null|callable(int, int, array<string, mixed>, int, int): void $onProgress
     * @return array<string, mixed>
     */
    public function migrate(array $filters, bool $resume, int $limit = 50, ?callable $onProgress = null): array
    {
        $this->preflight();
        $materials = $this->migrationCandidates($filters, $resume)->limit($limit)->get();
        $before = $this->reportSnapshot($materials->modelKeys());
        $started = microtime(true);
        $summary = [
            'selected' => $materials->count(),
            'copied' => 0,
            'verified' => 0,
            'switched' => 0,
            'failed' => 0,
            'bytes_copied' => 0,
            'outcomes' => [],
        ];
        $selectedBytes = $materials->sum(fn (ExpertProjectMaterial $item) => max(0, (int) $item->size));
        $progressBytes = 0;

        foreach ($materials as $index => $material) {
            $outcome = $this->migrateOne($material);
            if ($outcome['copied']) {
                ++$summary['copied'];
                $summary['bytes_copied'] += max(0, (int) $material->size);
            }
            if ($outcome['verified']) {
                ++$summary['verified'];
            }
            if ($outcome['switched']) {
                ++$summary['switched'];
            }
            if ($outcome['error_code'] !== null) {
                ++$summary['failed'];
            }
            $summary['outcomes'][] = [
                'material_public_id' => (string) $material->public_id,
                'status' => (string) $outcome['status'],
                'error_code' => $outcome['error_code'],
            ];

            if ($onProgress !== null) {
                $progressBytes += max(0, (int) $material->size);
                $onProgress($index + 1, $materials->count(), $outcome, $progressBytes, $selectedBytes);
            }
        }

        $after = $this->reportSnapshot($materials->modelKeys());

        return [
            ...$summary,
            'before' => $before,
            'after' => $after,
            'accounting_unchanged' => $before['usage_by_user'] === $after['usage_by_user'],
            'selected_material_totals_unchanged' => $before['selected_materials'] === $after['selected_materials'],
            'all_material_totals_unchanged' => $before['all_materials'] === $after['all_materials'],
            'duration_seconds' => round(microtime(true) - $started, 3),
        ];
    }

    /** @return array{success: bool, status: string, error_code: ?string} */
    public function rollback(int $materialId): array
    {
        if (! Schema::hasTable('expert_project_materials') || ! Schema::hasTable('expert_storage_migrations')) {
            return ['success' => false, 'status' => 'failed', 'error_code' => 'MIGRATION_JOURNAL_UNAVAILABLE'];
        }

        $journal = ExpertStorageMigration::query()->where('material_id', $materialId)->first();
        $material = ExpertProjectMaterial::query()->with('project')->find($materialId);
        if ($journal === null || $material === null) {
            return ['success' => false, 'status' => 'failed', 'error_code' => 'MIGRATION_NOT_FOUND'];
        }
        if ($journal->status === ExpertStorageMigration::STATUS_ROLLED_BACK) {
            return ['success' => true, 'status' => $journal->status, 'error_code' => null];
        }
        if (! in_array($journal->status, [ExpertStorageMigration::STATUS_SWITCHED, ExpertStorageMigration::STATUS_CLEANUP_PENDING], true)) {
            return ['success' => false, 'status' => $journal->status, 'error_code' => 'ROLLBACK_NOT_AVAILABLE'];
        }

        try {
            $this->verifyObject($journal->source_disk, $journal->source_key, (int) $journal->source_size, (string) $journal->source_sha256);
            DB::transaction(function () use ($materialId, $journal, $material): void {
                $locked = ExpertProjectMaterial::query()->lockForUpdate()->find($materialId);
                $lockedJournal = ExpertStorageMigration::query()->lockForUpdate()->find($journal->id);
                if ($locked === null || $lockedJournal === null || (int) $lockedJournal->material_id !== $materialId) {
                    throw new MigrationStepException('MATERIAL_MISSING');
                }
                if (! in_array($lockedJournal->status, [ExpertStorageMigration::STATUS_SWITCHED, ExpertStorageMigration::STATUS_CLEANUP_PENDING], true)) {
                    throw new MigrationStepException('ROLLBACK_NOT_AVAILABLE');
                }
                if ($locked->status !== 'uploaded'
                    || $locked->storageDisk() !== self::TARGET_DISK
                    || $locked->storageKey() !== $lockedJournal->target_key) {
                    throw new MigrationStepException('MATERIAL_POINTER_CHANGED');
                }

                $locked->forceFill([
                    'storage_disk' => $lockedJournal->source_disk,
                    'storage_path' => $lockedJournal->source_key,
                ])->save();
                $lockedJournal->forceFill([
                    'status' => ExpertStorageMigration::STATUS_ROLLED_BACK,
                    'last_error_code' => null,
                    'cleanup_after' => null,
                    'rolled_back_at' => now(),
                ])->save();
            }, 3);

            // Read through the same application path after the pointer switch.
            $fresh = $material->fresh();
            if ($fresh === null || $fresh->storageDisk() !== 'local' || ! $this->storage->exists('local', $fresh->storageKey())) {
                return ['success' => false, 'status' => ExpertStorageMigration::STATUS_ROLLED_BACK, 'error_code' => 'ROLLBACK_READ_FAILED'];
            }

            return ['success' => true, 'status' => ExpertStorageMigration::STATUS_ROLLED_BACK, 'error_code' => null];
        } catch (Throwable $exception) {
            $code = $this->errorCode($exception, 'ROLLBACK_FAILED');

            return ['success' => false, 'status' => 'failed', 'error_code' => $code];
        }
    }

    /** @return array{processed: int, removed: int, failed: int, skipped: int} */
    public function cleanupSources(int $limit = 100): array
    {
        $graceDays = $this->graceDays();
        if ($graceDays === null) {
            throw new MigrationStepException('GRACE_PERIOD_NOT_CONFIGURED');
        }

        $summary = ['processed' => 0, 'removed' => 0, 'failed' => 0, 'skipped' => 0];
        $journals = ExpertStorageMigration::query()
            ->where('status', ExpertStorageMigration::STATUS_CLEANUP_PENDING)
            ->whereNotNull('cleanup_after')
            ->where('cleanup_after', '<=', now())
            ->orderBy('id')->limit($limit)->get();

        foreach ($journals as $journal) {
            ++$summary['processed'];
            try {
                $result = $this->cleanupOneSource($journal->id);
                if ($result === 'removed') {
                    ++$summary['removed'];
                } elseif ($result === 'skipped') {
                    ++$summary['skipped'];
                } else {
                    ++$summary['failed'];
                }
            } catch (Throwable $exception) {
                ++$summary['failed'];
                ExpertStorageMigration::query()->whereKey($journal->id)->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error_code' => $this->errorCode($exception, 'SOURCE_CLEANUP_FAILED'),
                    'updated_at' => now(),
                ]);
            }
        }

        return $summary;
    }

    private function preflight(): void
    {
        if (! Schema::hasTable('expert_project_materials')) {
            throw new MigrationStepException('MATERIAL_TABLE_UNAVAILABLE');
        }
        if (! Schema::hasTable('expert_storage_migrations')) {
            throw new MigrationStepException('MIGRATION_JOURNAL_UNAVAILABLE');
        }
        try {
            ExpertStorageMigration::query()->limit(1)->get();
        } catch (Throwable) {
            throw new MigrationStepException('MIGRATION_JOURNAL_UNAVAILABLE');
        }

        // Validate source readability and target write/read/delete before any material can switch.
        $this->storage->healthCheck('local');
        $this->storage->healthCheck(self::TARGET_DISK);
    }

    /** @param array{user_id?: int, project_id?: int, material_id?: int} $filters */
    private function scopedMaterials(array $filters): Builder
    {
        return ExpertProjectMaterial::query()->with(['project', 'identity'])
            ->where('status', 'uploaded')
            ->when(isset($filters['user_id']), fn (Builder $query) => $query->whereHas('project', fn (Builder $projects) => $projects->where('user_id', $filters['user_id'])))
            ->when(isset($filters['project_id']), fn (Builder $query) => $query->where('expert_project_id', $filters['project_id']))
            ->when(isset($filters['material_id']), fn (Builder $query) => $query->whereKey($filters['material_id']));
    }

    /** @param array{user_id?: int, project_id?: int, material_id?: int} $filters */
    private function migrationCandidates(array $filters, bool $resume): Builder
    {
        $query = $this->scopedMaterials($filters);
        $query->where(function (Builder $items) use ($resume): void {
            $items->whereNull('storage_disk')->orWhere('storage_disk', 'local');
            if ($resume) {
                $items->orWhereHas('storageMigration', fn (Builder $migrations) => $migrations
                    ->whereNotIn('status', [ExpertStorageMigration::STATUS_COMPLETED, ExpertStorageMigration::STATUS_ROLLED_BACK]));
            }
        });

        return $query->orderBy('id');
    }

    /** @return array{status: string, error_code: ?string, copied: bool, verified: bool, switched: bool} */
    private function migrateOne(ExpertProjectMaterial $material): array
    {
        if ($material->storageDisk() === self::TARGET_DISK) {
            $journal = ExpertStorageMigration::query()->where('material_id', $material->id)->first();
            if ($journal === null) {
                return $this->outcome('already_s1');
            }
            if ($material->storageKey() !== $journal->target_key) {
                return $this->fail($journal, 'MATERIAL_POINTER_CHANGED');
            }
            if (in_array($journal->status, [ExpertStorageMigration::STATUS_COMPLETED, ExpertStorageMigration::STATUS_CLEANUP_PENDING], true)) {
                return $this->outcome($journal->status);
            }

            return $this->finishSwitched($material, $journal, copied: false);
        }

        if ($material->storageDisk() !== 'local' || $material->status !== 'uploaded' || $material->project === null) {
            return $this->outcome('skipped', 'UNSUPPORTED_SOURCE');
        }

        $targetKey = $this->targetKey($material);
        $journal = ExpertStorageMigration::query()->firstOrCreate(
            ['material_id' => $material->id],
            [
                'source_disk' => 'local',
                'source_key' => $material->storageKey(),
                'target_disk' => self::TARGET_DISK,
                'target_key' => $targetKey,
                'source_size' => (int) $material->size,
                'status' => ExpertStorageMigration::STATUS_PENDING,
            ],
        );

        if ($journal->source_disk !== 'local'
            || $journal->source_key !== $material->storageKey()
            || $journal->target_disk !== self::TARGET_DISK
            || $journal->target_key !== $targetKey) {
            return $this->fail($journal, 'MATERIAL_LOCATOR_CHANGED');
        }
        if ($journal->status === ExpertStorageMigration::STATUS_COMPLETED) {
            return $this->outcome($journal->status);
        }

        $journal->forceFill([
            'attempts' => (int) $journal->attempts + 1,
            'started_at' => now(),
            'last_error_code' => null,
        ])->save();

        $copied = false;
        $verified = false;
        try {
            $context = $this->storage->contextForMaterial($material);
            if (! $this->storage->exists('local', $journal->source_key, $context)) {
                return $this->fail($journal, 'SOURCE_MISSING');
            }
            $sourceSize = $this->storage->size('local', $journal->source_key, $context);
            if ($sourceSize !== (int) $material->size) {
                $journal->forceFill(['source_size' => $sourceSize])->save();

                return $this->fail($journal, 'SOURCE_SIZE_MISMATCH');
            }
            $sourceHash = $this->storage->sha256('local', $journal->source_key, $context);
            $identityHash = $this->trustedIdentityHash($material);
            if ($identityHash !== null && ! hash_equals($identityHash, strtolower($sourceHash))) {
                return $this->fail($journal, 'SOURCE_HASH_MISMATCH');
            }
            if ($identityHash !== null) {
                $sourceHash = $identityHash;
            }
            if ($journal->source_sha256 !== null && ! hash_equals((string) $journal->source_sha256, $sourceHash)) {
                return $this->fail($journal, 'SOURCE_CHANGED');
            }

            $journal->forceFill([
                'source_size' => $sourceSize,
                'source_sha256' => $sourceHash,
                'status' => ExpertStorageMigration::STATUS_COPYING,
                'last_error_code' => null,
            ])->save();

            if ($this->storage->exists(self::TARGET_DISK, $journal->target_key, $context)) {
                $existingSize = $this->storage->size(self::TARGET_DISK, $journal->target_key, $context);
                $existingHash = $this->storage->sha256(self::TARGET_DISK, $journal->target_key, $context);
                if ($existingSize !== $sourceSize || ! hash_equals($sourceHash, $existingHash)) {
                    $journal->forceFill([
                        'target_size' => $existingSize,
                        'target_sha256' => $existingHash,
                    ])->save();

                    return $this->fail($journal, 'TARGET_CONFLICT');
                }
                $journal->forceFill([
                    'target_size' => $existingSize,
                    'target_sha256' => $existingHash,
                    'copied_at' => $journal->copied_at ?? now(),
                    'status' => ExpertStorageMigration::STATUS_COPIED,
                ])->save();
            } else {
                // This unique key was observed absent immediately before this journal-owned write.
                $journal->forceFill(['target_created' => true])->save();
                $sourceStream = $this->storage->readStream('local', $journal->source_key, $context);
                try {
                    $this->storage->putStream(self::TARGET_DISK, $journal->target_key, $sourceStream, $context);
                } finally {
                    if (is_resource($sourceStream)) {
                        fclose($sourceStream);
                    }
                }
                $copied = true;
                $journal->forceFill([
                    'copied_at' => now(),
                    'status' => ExpertStorageMigration::STATUS_COPIED,
                ])->save();
            }

            $journal->forceFill(['status' => ExpertStorageMigration::STATUS_VERIFYING])->save();
            $this->verifyObject(self::TARGET_DISK, $journal->target_key, $sourceSize, $sourceHash, $context);
            $verified = true;
            $journal->forceFill([
                'target_size' => $sourceSize,
                'target_sha256' => $sourceHash,
                'verified_at' => now(),
                'status' => ExpertStorageMigration::STATUS_VERIFIED,
                'last_error_code' => null,
            ])->save();

            try {
                $switch = DB::transaction(function () use ($material, $journal): string {
                    $lockedMaterial = ExpertProjectMaterial::query()->lockForUpdate()->find($material->id);
                    $lockedJournal = ExpertStorageMigration::query()->lockForUpdate()->find($journal->id);
                    if ($lockedJournal === null || $lockedJournal->material_id === null || $lockedMaterial === null) {
                        return 'deleted';
                    }
                    if ($lockedMaterial->status !== 'uploaded') {
                        return 'inactive';
                    }
                    if ($lockedMaterial->storageDisk() === self::TARGET_DISK
                        && $lockedMaterial->storageKey() === $lockedJournal->target_key) {
                        $lockedJournal->forceFill([
                            'status' => ExpertStorageMigration::STATUS_SWITCHED,
                            'switched_at' => $lockedJournal->switched_at ?? now(),
                            'cleanup_after' => $lockedJournal->cleanup_after ?? $this->cleanupAfter(),
                        ])->save();

                        return 'switched';
                    }
                    if ($lockedMaterial->storageDisk() !== 'local'
                        || $lockedMaterial->storageKey() !== $lockedJournal->source_key) {
                        return 'changed';
                    }

                    $lockedMaterial->forceFill([
                        'storage_disk' => self::TARGET_DISK,
                        'storage_path' => $lockedJournal->target_key,
                    ])->save();
                    $lockedJournal->forceFill([
                        'status' => ExpertStorageMigration::STATUS_SWITCHED,
                        'switched_at' => now(),
                        'cleanup_after' => $this->cleanupAfter(),
                    ])->save();

                    return 'switched';
                }, 3);
            } catch (Throwable) {
                throw new MigrationStepException('DB_SWITCH_FAILED');
            }

            if ($switch === 'deleted' || $switch === 'inactive') {
                $this->scheduleOrphanTarget($journal);

                return $this->fail($journal, $switch === 'deleted' ? 'MATERIAL_DELETED' : 'MATERIAL_INACTIVE', $copied, $verified);
            }
            if ($switch === 'changed') {
                return $this->fail($journal, 'MATERIAL_POINTER_CHANGED', $copied, $verified);
            }

            return $this->finishSwitched($material->fresh() ?? $material, $journal->fresh() ?? $journal, $copied);
        } catch (Throwable $exception) {
            return $this->fail($journal->fresh() ?? $journal, $this->errorCode($exception, 'MIGRATION_FAILED'), $copied, $verified);
        }
    }

    /** @return array{status: string, error_code: ?string, copied: bool, verified: bool, switched: bool} */
    private function finishSwitched(ExpertProjectMaterial $material, ExpertStorageMigration $journal, bool $copied): array
    {
        try {
            if ($material->storageDisk() !== self::TARGET_DISK || $material->storageKey() !== $journal->target_key) {
                return $this->fail($journal, 'MATERIAL_POINTER_CHANGED', $copied);
            }
            $stream = $this->storage->readStream(self::TARGET_DISK, $journal->target_key, $this->storage->contextForMaterial($material));
            fclose($stream);
            $journal->forceFill([
                'status' => ExpertStorageMigration::STATUS_CLEANUP_PENDING,
                'last_error_code' => null,
                'cleanup_after' => $journal->cleanup_after ?? $this->cleanupAfter(),
            ])->save();

            return $this->outcome($journal->status, null, $copied, true, true);
        } catch (Throwable $exception) {
            $this->restoreLocalPointerAfterFailedPostCheck($material, $journal);

            return $this->fail($journal->fresh() ?? $journal, $this->errorCode($exception, 'POST_SWITCH_VERIFY_FAILED'), $copied, true);
        }
    }

    private function restoreLocalPointerAfterFailedPostCheck(ExpertProjectMaterial $material, ExpertStorageMigration $journal): void
    {
        try {
            if (! $this->storage->exists($journal->source_disk, $journal->source_key)) {
                return;
            }
            DB::transaction(function () use ($material, $journal): void {
                $locked = ExpertProjectMaterial::query()->lockForUpdate()->find($material->id);
                $lockedJournal = ExpertStorageMigration::query()->lockForUpdate()->find($journal->id);
                if ($locked !== null && $lockedJournal !== null
                    && $locked->storageDisk() === self::TARGET_DISK
                    && $locked->storageKey() === $lockedJournal->target_key) {
                    $locked->forceFill([
                        'storage_disk' => $lockedJournal->source_disk,
                        'storage_path' => $lockedJournal->source_key,
                    ])->save();
                    $lockedJournal->forceFill([
                        'status' => ExpertStorageMigration::STATUS_FAILED,
                        'last_error_code' => 'POST_SWITCH_VERIFY_FAILED',
                    ])->save();
                }
            }, 3);
        } catch (Throwable) {
            // Keep the original stable verification error; the journal remains recoverable.
        }
    }

    private function cleanupOneSource(int $journalId): string
    {
        return DB::transaction(function () use ($journalId): string {
            $journal = ExpertStorageMigration::query()->lockForUpdate()->find($journalId);
            if ($journal === null || $journal->status !== ExpertStorageMigration::STATUS_CLEANUP_PENDING) {
                return 'skipped';
            }
            if ($journal->cleanup_after === null || $journal->cleanup_after->isFuture()) {
                return 'skipped';
            }
            if ($journal->verified_at === null
                || ! is_string($journal->source_sha256)
                || ! is_string($journal->target_sha256)
                || $journal->source_sha256 === ''
                || ! hash_equals($journal->source_sha256, $journal->target_sha256)
                || (int) $journal->source_size !== (int) $journal->target_size) {
                throw new MigrationStepException('MIGRATION_NOT_VERIFIED');
            }
            $material = ExpertProjectMaterial::query()->lockForUpdate()->find($journal->material_id);
            if ($material === null) {
                // The material was deleted after switching; clean only its journaled
                // source after grace and make a remaining exact S1 object retryable.
                if ($this->storage->exists($journal->target_disk, $journal->target_key)) {
                    $this->cleanup->scheduleFile($journal->target_key, $journal->target_disk);
                }
                if ($this->storage->exists($journal->source_disk, $journal->source_key)) {
                    $this->verifyObject($journal->source_disk, $journal->source_key, (int) $journal->source_size, (string) $journal->source_sha256);
                    $this->storage->delete($journal->source_disk, $journal->source_key);
                    if ($this->storage->exists($journal->source_disk, $journal->source_key)) {
                        throw new MigrationStepException('SOURCE_CLEANUP_FAILED');
                    }
                }
                $journal->forceFill([
                    'status' => ExpertStorageMigration::STATUS_COMPLETED,
                    'cleaned_at' => now(),
                    'last_error_code' => null,
                ])->save();

                return 'removed';
            }
            if ($material->status !== 'uploaded'
                || $material->storageDisk() !== self::TARGET_DISK
                || $material->storageKey() !== $journal->target_key) {
                $journal->forceFill(['last_error_code' => 'MATERIAL_POINTER_CHANGED'])->save();

                return 'skipped';
            }

            $this->verifyObject($journal->target_disk, $journal->target_key, (int) $journal->target_size, (string) $journal->target_sha256);

            if (! $this->storage->exists($journal->source_disk, $journal->source_key)) {
                $journal->forceFill([
                    'status' => ExpertStorageMigration::STATUS_COMPLETED,
                    'cleaned_at' => now(),
                    'last_error_code' => null,
                ])->save();

                return 'removed';
            }
            $this->verifyObject($journal->source_disk, $journal->source_key, (int) $journal->source_size, (string) $journal->source_sha256);
            $this->storage->delete($journal->source_disk, $journal->source_key);
            if ($this->storage->exists($journal->source_disk, $journal->source_key)) {
                throw new MigrationStepException('SOURCE_CLEANUP_FAILED');
            }
            $journal->forceFill([
                'status' => ExpertStorageMigration::STATUS_COMPLETED,
                'cleaned_at' => now(),
                'last_error_code' => null,
            ])->save();

            return 'removed';
        }, 3);
    }

    /** @return array{status: string, error_code: ?string, copied: bool, verified: bool, switched: bool} */
    private function fail(ExpertStorageMigration $journal, string $code, bool $copied = false, bool $verified = false): array
    {
        $journal->forceFill([
            'status' => ExpertStorageMigration::STATUS_FAILED,
            'last_error_code' => $code,
        ])->save();

        return $this->outcome(ExpertStorageMigration::STATUS_FAILED, $code, $copied, $verified, false);
    }

    /** @return array{status: string, error_code: ?string, copied: bool, verified: bool, switched: bool} */
    private function outcome(string $status, ?string $errorCode = null, bool $copied = false, bool $verified = false, bool $switched = false): array
    {
        return ['status' => $status, 'error_code' => $errorCode, 'copied' => $copied, 'verified' => $verified, 'switched' => $switched];
    }

    private function targetKey(ExpertProjectMaterial $material): string
    {
        $projectId = (string) $material->project?->public_id;
        $materialId = (string) $material->public_id;
        $extension = strtolower((string) $material->extension);
        if ($projectId === '' || $materialId === '' || preg_match('/^[a-z0-9]{1,16}$/', $extension) !== 1) {
            throw new MigrationStepException('INVALID_TARGET_KEY');
        }

        return "expert/{$projectId}/materials/{$materialId}.{$extension}";
    }

    private function trustedIdentityHash(ExpertProjectMaterial $material): ?string
    {
        $identity = $material->identity;
        if ($identity === null || $identity->state === 'stale') {
            return null;
        }
        $hash = strtolower((string) $identity->source_sha256);

        return preg_match('/^[a-f0-9]{64}$/', $hash) === 1 ? $hash : null;
    }

    private function verifyObject(string $disk, string $key, int $expectedSize, string $expectedHash, array $context = []): void
    {
        if (! $this->storage->exists($disk, $key, $context)) {
            throw new MigrationStepException($disk === 'local' ? 'SOURCE_MISSING' : 'TARGET_VERIFY_FAILED');
        }
        $actualSize = $this->storage->size($disk, $key, $context);
        if ($actualSize !== $expectedSize) {
            throw new MigrationStepException($disk === 'local' ? 'SOURCE_SIZE_MISMATCH' : 'TARGET_SIZE_MISMATCH');
        }
        $actualHash = $this->storage->sha256($disk, $key, $context);
        if (! hash_equals($expectedHash, $actualHash)) {
            throw new MigrationStepException($disk === 'local' ? 'SOURCE_HASH_MISMATCH' : 'TARGET_SHA256_MISMATCH');
        }
    }

    /** @return array<string, mixed> */
    private function reportSnapshot(array $materialIds): array
    {
        $rows = $materialIds === [] ? collect() : DB::table('expert_project_materials as materials')
            ->join('expert_projects as projects', 'projects.id', '=', 'materials.expert_project_id')
            ->whereIn('materials.id', $materialIds)
            ->selectRaw('projects.user_id AS user_id, COUNT(*) AS files, COALESCE(SUM(materials.size), 0) AS bytes')
            ->groupBy('projects.user_id')->get();
        $usage = DB::table('expert_storage_usages')
            ->orderBy('user_id')->get(['user_id', 'originals_bytes', 'materials_count'])
            ->mapWithKeys(fn ($row) => [(int) $row->user_id => [
                'bytes' => (int) $row->originals_bytes,
                'files' => (int) $row->materials_count,
            ]])->all();
        $files = 0;
        $bytes = 0;
        foreach ($rows as $row) {
            $files += (int) $row->files;
            $bytes += (int) $row->bytes;
        }
        ksort($usage);
        $all = DB::table('expert_project_materials')
            ->selectRaw('COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes')->first();

        return [
            'selected_materials' => ['files' => $files, 'bytes' => $bytes],
            'all_materials' => ['files' => (int) ($all->files ?? 0), 'bytes' => (int) ($all->bytes ?? 0)],
            'usage_by_user' => $usage,
        ];
    }

    private function s1Configured(): bool
    {
        foreach (['key', 'secret', 'region', 'bucket', 'endpoint'] as $name) {
            if (! is_string(config("filesystems.disks.s1.{$name}")) || trim((string) config("filesystems.disks.s1.{$name}")) === '') {
                return false;
            }
        }

        return true;
    }

    private function cleanupAfter(): ?\Illuminate\Support\Carbon
    {
        $days = $this->graceDays();

        return $days === null ? null : now()->addDays($days);
    }

    private function graceDays(): ?int
    {
        $value = config('expert.storage_migration.grace_days');
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            return null;
        }

        return (int) $value;
    }

    private function scheduleOrphanTarget(ExpertStorageMigration $journal): void
    {
        if ($journal->target_created) {
            try {
                $this->cleanup->scheduleFile($journal->target_key, $journal->target_disk);
            } catch (Throwable) {
                // Keep the journal as the recoverable record if cleanup scheduling is unavailable.
            }
        }
    }

    private function errorCode(Throwable $exception, string $fallback): string
    {
        if ($exception instanceof MigrationStepException) {
            return $exception->errorCode;
        }
        if ($exception instanceof ExpertStorageException) {
            return $exception->failureCode;
        }

        return $fallback;
    }
}

final class MigrationStepException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
