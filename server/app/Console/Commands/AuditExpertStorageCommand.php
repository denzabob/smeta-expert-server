<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertStorageException;
use App\Services\Expert\ExpertStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AuditExpertStorageCommand extends Command
{
    protected $signature = 'expert:storage-audit
        {--user= : Scope to a user id}
        {--project= : Scope to a project id or public id}
        {--material= : Scope to a material id or public id}
        {--all : Audit all material rows}
        {--hash : Stream each object to calculate SHA-256}';

    protected $description = 'Compare Expert material database locators with physical storage objects.';

    public function handle(ExpertStorageService $storage): int
    {
        $scope = $this->resolveScope();
        if ($scope === null) {
            return self::FAILURE;
        }
        if (! Schema::hasTable('expert_project_materials')) {
            $this->error('Storage audit unavailable (MATERIAL_TABLE_UNAVAILABLE). Запустите штатные database migrations.');

            return self::FAILURE;
        }
        if (! Schema::hasTable('expert_storage_upload_reservations')) {
            $this->error('Storage audit unavailable (STORAGE_RESERVATION_TABLE_UNAVAILABLE). Запустите штатные database migrations.');

            return self::FAILURE;
        }
        $query = ExpertProjectMaterial::query()->with(['project', 'storageMigration', 'identity'])
            ->when(isset($scope['user_id']), fn ($items) => $items->whereHas('project', fn ($projects) => $projects->where('user_id', $scope['user_id'])))
            ->when(isset($scope['project_id']), fn ($items) => $items->where('expert_project_id', $scope['project_id']))
            ->when(isset($scope['material_id']), fn ($items) => $items->whereKey($scope['material_id']))
            ->orderBy('id');

        $summary = [
            'materials' => 0,
            'physical_missing' => 0,
            'size_mismatch' => 0,
            'hash_mismatch' => 0,
            'read_failed' => 0,
            'broken_pointers' => 0,
            'non_s1_materials' => 0,
        ];
        $withHash = (bool) $this->option('hash');
        $query->chunkById(100, function ($materials) use ($storage, &$summary, $withHash): void {
            foreach ($materials as $material) {
                ++$summary['materials'];
                $disk = $storage->diskForMaterial($material);
                $key = $storage->keyForMaterial($material);
                $context = $storage->contextForMaterial($material);
                $expectedPrefix = 'expert/'.($material->project?->public_id ?? '').'/materials/';
                if ($material->status !== 'uploaded' || $material->project === null || ! str_starts_with($key, $expectedPrefix)) {
                    ++$summary['broken_pointers'];
                    $this->line("{$material->public_id} | BROKEN_POINTER");
                }
                if ($disk !== 's1') {
                    ++$summary['non_s1_materials'];
                    $this->line("{$material->public_id} | NON_S1_PRIMARY | {$disk}");
                }
                try {
                    if (! $storage->exists($disk, $key, $context)) {
                        ++$summary['physical_missing'];
                        $this->line("{$material->public_id} | PHYSICAL_MISSING | {$disk} | DB size {$material->size}");
                        continue;
                    }
                    $physicalSize = $storage->size($disk, $key, $context);
                    if ($physicalSize !== (int) $material->size) {
                        ++$summary['size_mismatch'];
                        $this->line("{$material->public_id} | SIZE_MISMATCH | DB {$material->size} | physical {$physicalSize}");
                    }
                    if ($withHash) {
                        $hash = $storage->sha256($disk, $key, $context);
                        $migration = $material->storageMigration;
                        $expected = null;
                        if ($migration !== null && $disk === $migration->source_disk && $key === $migration->source_key) {
                            $expected = $migration->source_sha256;
                        } elseif ($migration !== null && $disk === $migration->target_disk && $key === $migration->target_key) {
                            $expected = $migration->target_sha256;
                        } elseif ($material->identity !== null && $material->identity->state !== 'stale') {
                            $identityHash = strtolower((string) $material->identity->source_sha256);
                            if (preg_match('/^[a-f0-9]{64}$/', $identityHash) === 1) {
                                $expected = $identityHash;
                            }
                        }
                        if (is_string($expected) && $expected !== '' && ! hash_equals($expected, $hash)) {
                            ++$summary['hash_mismatch'];
                            $this->line("{$material->public_id} | HASH_MISMATCH | {$hash}");
                        } else {
                            $this->line("{$material->public_id} | HASH {$hash}".($expected === null ? ' | no journal checksum' : ' | verified'));
                        }
                    }
                } catch (Throwable $exception) {
                    ++$summary['read_failed'];
                    $code = $exception instanceof ExpertStorageException ? $exception->failureCode : 'STORAGE_AUDIT_FAILED';
                    $this->line("{$material->public_id} | {$code}");
                }
            }
        });

        $reservationQuery = DB::table('expert_storage_upload_reservations')
            ->where('status', 'reserved')
            ->where('expires_at', '<=', now());
        if (isset($scope['user_id'])) {
            $reservationQuery->where('user_id', $scope['user_id']);
        } elseif (isset($scope['project_id'])) {
            $reservationQuery->where('expert_project_id', $scope['project_id']);
        } elseif (isset($scope['material_id'])) {
            $projectId = ExpertProjectMaterial::query()->whereKey($scope['material_id'])->value('expert_project_id');
            $reservationQuery->where('expert_project_id', $projectId);
        }
        $staleReservations = $reservationQuery->count();

        $localOriginals = 0;
        try {
            $localInventory = $storage->inventoryPrefix(
                'local',
                'expert',
                ['operation' => 'storage_audit_local'],
                static fn (string $key): bool => preg_match('#^expert/[^/]+/materials/.+$#', $key) === 1,
            );
            $localOriginals = $localInventory['files'];
        } catch (Throwable $exception) {
            ++$summary['read_failed'];
            $code = $exception instanceof ExpertStorageException ? $exception->failureCode : 'STORAGE_AUDIT_FAILED';
            $this->line("LOCAL_PERSISTENT_AUDIT_FAILED | {$code}");
        }

        $this->info(sprintf(
            'Materials: %d | Physical missing: %d | Size mismatch: %d | Hash mismatch: %d | Broken pointers: %d | Non-S1 materials: %d | Stale reservations: %d | Unexpected local persistent files: %d | Read failed: %d',
            $summary['materials'], $summary['physical_missing'], $summary['size_mismatch'], $summary['hash_mismatch'],
            $summary['broken_pointers'], $summary['non_s1_materials'], $staleReservations, $localOriginals, $summary['read_failed'],
        ));

        return $summary['physical_missing'] + $summary['size_mismatch'] + $summary['hash_mismatch']
            + $summary['broken_pointers'] + $summary['non_s1_materials'] + $staleReservations + $localOriginals + $summary['read_failed'] === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    /** @return array<string, int>|null */
    private function resolveScope(): ?array
    {
        $provided = [];
        foreach (['user', 'project', 'material'] as $name) {
            $value = $this->option($name);
            if ($value !== null && trim((string) $value) !== '') {
                $provided[$name] = trim((string) $value);
            }
        }
        $all = (bool) $this->option('all');
        if (count($provided) + (int) $all !== 1) {
            $this->error('Укажите ровно один режим: --user, --project, --material или --all.');

            return null;
        }
        if (isset($provided['user'])) {
            if (! ctype_digit($provided['user']) || (int) $provided['user'] < 1
                || ! User::withTrashed()->whereKey((int) $provided['user'])->exists()) {
                $this->error('Параметр --user должен указывать на существующий user id.');

                return null;
            }

            return ['user_id' => (int) $provided['user']];
        }
        if (isset($provided['project'])) {
            $project = ctype_digit($provided['project'])
                ? ExpertProject::query()->find((int) $provided['project'])
                : ExpertProject::query()->where('public_id', $provided['project'])->first();
            if ($project === null) {
                $this->error('Проект не найден.');

                return null;
            }

            return ['project_id' => (int) $project->id];
        }
        if (isset($provided['material'])) {
            $material = ctype_digit($provided['material'])
                ? ExpertProjectMaterial::query()->find((int) $provided['material'])
                : ExpertProjectMaterial::query()->where('public_id', $provided['material'])->first();
            if ($material === null) {
                $this->error('Material не найден.');

                return null;
            }

            return ['material_id' => (int) $material->id];
        }

        return [];
    }
}
