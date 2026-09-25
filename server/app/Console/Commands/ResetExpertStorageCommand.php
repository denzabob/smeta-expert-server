<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Expert\ExpertProjectMaterial;
use App\Services\Expert\ExpertMaterialService;
use App\Services\Expert\ExpertStorageException;
use App\Services\Expert\ExpertStorageService;
use App\Services\Expert\ExpertStorageUsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ResetExpertStorageCommand extends Command
{
    protected $signature = 'expert:storage-reset
        {--confirm : Confirm irreversible deletion of all Expert user files and references}
        {--allow-production : Allow the destructive reset when APP_ENV=production}';

    protected $description = 'Remove Expert user files and their storage references, then recalculate Expert usage.';

    public function handle(
        ExpertMaterialService $materials,
        ExpertStorageService $storage,
        ExpertStorageUsageService $usage,
    ): int {
        $this->warn('Необратимая очистка всех пользовательских файлов Expert. Проекты, сообщения и остальные нефайловые данные сохраняются.');

        $schemaAvailable = $this->hasRequiredSchema();
        if (! (bool) $this->option('confirm')) {
            if ($schemaAvailable) {
                $this->printDbPlan();
            } else {
                $this->line('DB plan unavailable: RESET_SCHEMA_UNAVAILABLE.');
            }
            $this->error('REFUSING_DESTRUCTIVE_OPERATION: повторите команду с --confirm после проверки плана.');

            return self::FAILURE;
        }
        if (app()->environment('production') && ! (bool) $this->option('allow-production')) {
            $this->error('REFUSING_DESTRUCTIVE_OPERATION: в production требуется отдельный --allow-production override.');

            return self::FAILURE;
        }
        if (! $schemaAvailable) {
            $this->error('RESET_SCHEMA_UNAVAILABLE');

            return self::FAILURE;
        }

        $this->printDbPlan();
        $initialFileDbObjects = $this->fileDbObjectCount();

        try {
            $activeReservations = DB::table('expert_storage_upload_reservations')
                ->where('status', 'reserved')
                ->where('expires_at', '>', now())
                ->count();
            if ($activeReservations > 0) {
                $this->error("ACTIVE_STORAGE_RESERVATIONS: {$activeReservations}; дождитесь завершения загрузок и повторите reset.");

                return self::FAILURE;
            }

            if (Schema::hasColumn('expert_project_materials', 'storage_disk')) {
                $unknownDisk = DB::table('expert_project_materials')
                    ->whereNotNull('storage_disk')
                    ->whereNotIn('storage_disk', ['local', 's1'])
                    ->value('storage_disk');
                if (is_string($unknownDisk) && $unknownDisk !== '') {
                    $this->error('UNSUPPORTED_EXPERT_STORAGE_DISK: reset не будет трогать неизвестный disk.');

                    return self::FAILURE;
                }
            }

            $before = [];
            foreach (['local', 's1'] as $disk) {
                $before[$disk] = $storage->inventoryPrefix(
                    $disk,
                    'expert',
                    ['operation' => 'storage_reset_preflight'],
                    static fn (string $key): bool => ! str_starts_with($key, 'expert/health-checks/'),
                );
            }
        } catch (Throwable $exception) {
            $code = $exception instanceof ExpertStorageException ? $exception->failureCode : 'STORAGE_RESET_PREFLIGHT_FAILED';
            $this->error("RESET_PREFLIGHT_FAILED: {$code}");

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Physical plan: local %d files / %d bytes; s1 %d files / %d bytes.',
            $before['local']['files'], $before['local']['bytes'], $before['s1']['files'], $before['s1']['bytes'],
        ));

        $errors = 0;
        $removedMaterials = 0;
        ExpertProjectMaterial::query()->orderBy('id')->chunkById(100, function ($batch) use ($materials, &$errors, &$removedMaterials): void {
            foreach ($batch as $material) {
                try {
                    $materials->delete($material, allowLinkedFindings: true);
                    if (! ExpertProjectMaterial::query()->whereKey($material->id)->exists()) {
                        ++$removedMaterials;
                    } else {
                        ++$errors;
                        $this->line("RESET_MATERIAL_DELETE_FAILED: {$material->public_id}");
                    }
                } catch (Throwable $exception) {
                    ++$errors;
                    $code = $exception instanceof ExpertStorageException ? $exception->failureCode : 'RESET_MATERIAL_DELETE_FAILED';
                    $this->line("RESET_MATERIAL_DELETE_FAILED: {$material->public_id} | {$code}");
                }
            }
        });

        $remainingMaterials = ExpertProjectMaterial::query()->count();
        if ($remainingMaterials > 0) {
            try {
                $usage->recalculateAll();
            } catch (Throwable) {
                ++$errors;
            }
            $this->report(max(0, $initialFileDbObjects - $this->fileDbObjectCount()), 0, 0, $errors + $remainingMaterials);

            return self::FAILURE;
        }

        $this->deleteRowsInChunks('expert_storage_upload_reservations');
        $this->deleteRowsInChunks('expert_storage_migrations');
        if (Schema::hasTable('expert_message_materials')) {
            $this->deleteRowsInChunks('expert_message_materials');
        }

        try {
            $usage->recalculateAll();
        } catch (Throwable) {
            ++$errors;
        }

        $physicalFilesRemoved = 0;
        $bytesRemoved = 0;
        foreach (['local', 's1'] as $disk) {
            try {
                $storage->deletePrefix(
                    $disk,
                    'expert',
                    ['operation' => 'storage_reset'],
                    static fn (string $key): bool => ! str_starts_with($key, 'expert/health-checks/'),
                );
                $after = $storage->inventoryPrefix(
                    $disk,
                    'expert',
                    ['operation' => 'storage_reset_verify'],
                    static fn (string $key): bool => ! str_starts_with($key, 'expert/health-checks/'),
                );
                $physicalFilesRemoved += max(0, $before[$disk]['files'] - $after['files']);
                $bytesRemoved += max(0, $before[$disk]['bytes'] - $after['bytes']);
                if ($after['files'] > 0 || $after['bytes'] > 0) {
                    ++$errors;
                }
            } catch (Throwable $exception) {
                ++$errors;
                $code = $exception instanceof ExpertStorageException ? $exception->failureCode : 'STORAGE_RESET_DELETE_FAILED';
                $this->line("PHYSICAL_CLEANUP_FAILED: {$disk} | {$code}");
                try {
                    $after = $storage->inventoryPrefix(
                        $disk,
                        'expert',
                        ['operation' => 'storage_reset_verify'],
                        static fn (string $key): bool => ! str_starts_with($key, 'expert/health-checks/'),
                    );
                    $physicalFilesRemoved += max(0, $before[$disk]['files'] - $after['files']);
                    $bytesRemoved += max(0, $before[$disk]['bytes'] - $after['bytes']);
                } catch (Throwable) {
                    // The failed disk remains visible in the error count.
                }
            }
        }

        if ($errors === 0) {
            $this->deleteRowsInChunks('expert_storage_cleanup_tasks');
        }

        Log::warning('Expert storage reset completed.', [
            'materials_removed' => $removedMaterials,
            'physical_files_removed' => $physicalFilesRemoved,
            'bytes_removed' => $bytesRemoved,
            'errors' => $errors,
        ]);

        $this->report(max(0, $initialFileDbObjects - $this->fileDbObjectCount()), $physicalFilesRemoved, $bytesRemoved, $errors);

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function hasRequiredSchema(): bool
    {
        foreach ([
            'expert_projects',
            'expert_project_materials',
            'expert_storage_usages',
            'expert_storage_cleanup_tasks',
            'expert_storage_migrations',
            'expert_storage_upload_reservations',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    private function printDbPlan(): void
    {
        $materialCount = ExpertProjectMaterial::query()->count();
        $materialBytes = (int) ExpertProjectMaterial::query()->sum('size');
        $messageLinks = Schema::hasTable('expert_message_materials')
            ? DB::table('expert_message_materials')->count()
            : 0;
        $reservationCount = DB::table('expert_storage_upload_reservations')->count();
        $migrationCount = DB::table('expert_storage_migrations')->count();

        $this->line("DB plan: materials {$materialCount}, {$materialBytes} bytes; message attachment links {$messageLinks}; reservations {$reservationCount}; migration journal rows {$migrationCount}.");
    }

    private function deleteRowsInChunks(string $table): void
    {
        DB::table($table)->orderBy('id')->chunkById(500, function ($rows) use ($table): void {
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->delete();
            }
        });
    }

    private function fileDbObjectCount(): int
    {
        $count = ExpertProjectMaterial::query()->count();
        foreach (['expert_message_materials', 'expert_storage_upload_reservations', 'expert_storage_migrations', 'expert_storage_cleanup_tasks', 'expert_material_identities'] as $table) {
            if (Schema::hasTable($table)) {
                $count += DB::table($table)->count();
            }
        }

        return $count;
    }

    private function report(int $objects, int $files, int $bytes, int $errors): void
    {
        $this->line("DB objects removed: {$objects}");
        $this->line("Physical files removed: {$files}");
        $this->line("Bytes removed: {$bytes}");
        $this->line("Errors: {$errors}");
    }
}
