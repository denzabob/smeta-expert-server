<?php

namespace App\Console\Commands;

use App\Services\Storage\ObjectStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ResetSmetaStorage extends Command
{
    protected $signature = 'smeta:storage-reset {--confirm : Confirm irreversible deletion of Smeta file storage} {--allow-production : Allow reset while APP_ENV=production}';

    protected $description = 'Удалить тестовые файлы и ссылки на файлы модуля «Сметы»';

    private const LEGACY_PREFIXES = [
        'imports',
        'price_imports',
        'price_import_foundation',
        'price-lists',
        'evidence-records',
        'screenshots',
        'finished-product-evidence',
        'evidence/documents/expenses',
    ];

    /** @var array<string, list<string>> */
    private const FILE_LOCATORS = [
        'import_sessions' => ['file_path'],
        'price_import_sessions' => ['file_path'],
        'price_imports' => ['file_path'],
        'price_list_versions' => ['file_path'],
        'evidence_assets' => ['file_path'],
        'generic_evidence_assets' => ['file_path'],
        'finished_product_price_evidence_assets' => ['file_path'],
        'evidence_artifacts' => ['screenshot_path'],
        'material_price_histories' => ['screenshot_path', 'snapshot_path'],
        'materials' => ['last_price_screenshot_path'],
    ];

    public function handle(ObjectStorage $storage): int
    {
        if (!$this->option('confirm')) {
            $this->error('REFUSING_DESTRUCTIVE_OPERATION: добавьте --confirm для безвозвратного удаления тестовых файлов «Смет»');
            return self::FAILURE;
        }

        if (app()->environment('production') && !$this->option('allow-production')) {
            $this->error('REFUSING_PRODUCTION_RESET: в production требуется явный флаг --allow-production');
            return self::FAILURE;
        }

        try {
            if (Schema::hasTable('storage_upload_reservations') && DB::table('storage_upload_reservations')
                ->where('module', 'smeta')->where('status', 'reserved')->where('expires_at', '>', now())->exists()) {
                $this->error('ACTIVE_STORAGE_RESERVATIONS: завершите загрузки Смет перед reset.');
                return self::FAILURE;
            }
            $files = $this->plannedFiles($storage);
            $bytes = 0;
            foreach ($files as $disk => $paths) {
                foreach ($paths as $path) {
                    $bytes += $storage->size($disk, $path);
                }
            }
        } catch (Throwable) {
            $this->error('STORAGE_BACKEND_UNAVAILABLE: не удалось получить список файлов для безопасного сброса');
            return self::FAILURE;
        }

        $physicalCount = array_sum(array_map('count', $files));
        $this->warn('Операция необратима: удаляются пользовательские файлы модуля «Сметы» и их ссылки в базе. Legacy-файлы не переносятся.');
        $this->line("Files planned: {$physicalCount}");
        $this->line("Bytes planned: {$bytes}");

        try {
            $databaseObjectsRemoved = DB::transaction(function () {
                $changed = $this->clearDatabaseReferences();
                if (Schema::hasTable('storage_files')) {
                    $usage = app(\App\Services\Storage\StorageUsageService::class);
                    $files = DB::table('storage_files')->where('module', 'smeta')->get();
                    foreach ($files as $file) {
                        if ($file->user_id !== null) {
                            $usage->lockUserUsage((int) $file->user_id);
                        }
                        DB::table('storage_file_links')->where('storage_file_id', $file->id)->where('module', 'smeta')->delete();
                        if (!DB::table('storage_file_links')->where('storage_file_id', $file->id)->exists()) {
                            DB::table('storage_files')->where('id', $file->id)->update(['status' => 'deleting', 'updated_at' => now()]);
                        }
                    }
                    foreach (DB::table('storage_upload_reservations')->where('module', 'smeta')->where('status', 'reserved')->pluck('reservation_id') as $id) {
                        $usage->release($id);
                    }
                    foreach ($files->pluck('user_id')->filter()->unique() as $id) {
                        $usage->recalculate((int) $id);
                    }
                }
                return $changed;
            });
        } catch (Throwable $exception) {
            Log::error('Smeta storage reset database failure', [
                'exception' => $exception,
            ]);

            $this->error('DATABASE_RESET_FAILED: ссылки на файлы не очищены, физические файлы сохранены');
            return self::FAILURE;
        }

        $removedFiles = 0;
        $removedBytes = 0;
        $errors = 0;

        foreach ($files as $disk => $paths) {
            foreach ($paths as $path) {
                try {
                    $size = $storage->size($disk, $path);
                    if ($storage->delete($disk, $path)) {
                        if (Schema::hasTable('storage_files')) {
                            DB::table('storage_files')->where('disk', $disk)->where('path', $path)->where('status', 'deleting')
                                ->update(['status' => 'deleted', 'deleted_at' => now(), 'updated_at' => now()]);
                        }
                        $removedFiles++;
                        $removedBytes += $size;
                    } else {
                        $errors++;
                    }
                } catch (Throwable) {
                    $errors++;
                }
            }
        }

        $this->line("DB objects removed: {$databaseObjectsRemoved}");
        $this->line("Physical files removed: {$removedFiles}");
        $this->line("Bytes removed: {$removedBytes}");
        $this->line("Errors: {$errors}");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, list<string>> */
    private function plannedFiles(ObjectStorage $storage): array
    {
        $files = [ObjectStorage::DISK => $storage->listSmetaObjects()];

        foreach ([
            'local' => ['imports', 'price_imports', 'price_import_foundation', 'price-lists'],
            'public' => ['price_imports', 'price-lists', 'evidence-records', 'screenshots', 'finished-product-evidence', 'evidence/documents/expenses'],
        ] as $disk => $prefixes) {
            $files[$disk] = [];
            foreach ($prefixes as $prefix) {
                $files[$disk] = [...$files[$disk], ...Storage::disk($disk)->allFiles($prefix)];
            }
            $files[$disk] = array_values(array_unique($files[$disk]));
        }

        if (Schema::hasTable('storage_files')) {
            $protected = DB::table('storage_files as files')->join('storage_file_links as links', 'links.storage_file_id', '=', 'files.id')
                ->where('links.module', '!=', 'smeta')->select('files.disk', 'files.path')->distinct()->get();
            foreach ($protected as $file) {
                $files[$file->disk] = array_values(array_diff($files[$file->disk] ?? [], [$file->path]));
            }
        }

        return $files;
    }

    private function clearDatabaseReferences(): int
    {
        $changed = 0;
        $patterns = array_map(fn (string $prefix) => $prefix . '/%', ['smeta', ...self::LEGACY_PREFIXES]);

        foreach (self::FILE_LOCATORS as $table => $columns) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'id')) {
                continue;
            }

            $existingColumns = array_values(array_filter($columns, fn (string $column) => Schema::hasColumn($table, $column)));
            if ($existingColumns === []) {
                continue;
            }

            $query = DB::table($table)->where(function ($query) use ($existingColumns, $patterns) {
                foreach ($existingColumns as $column) {
                    foreach ($patterns as $pattern) {
                        $query->orWhere($column, 'like', $pattern);
                    }
                }
            });

            if ($table === 'import_sessions') {
                $ids = $query->pluck('id');
                if ($ids->isEmpty()) {
                    continue;
                }

                if (Schema::hasTable('import_column_mappings')
                    && Schema::hasColumn('import_column_mappings', 'import_session_id')) {
                    $changed += DB::table('import_column_mappings')
                        ->whereIn('import_session_id', $ids)
                        ->delete();
                }

                $changed += DB::table($table)->whereIn('id', $ids)->delete();
                continue;
            }

            if (in_array($table, ['evidence_assets', 'generic_evidence_assets'], true)) {
                $ids = $query->pluck('id');
                if ($ids->isNotEmpty()) {
                    $changed += DB::table($table)->whereIn('id', $ids)->delete();
                }
                continue;
            }

            $extra = [];
            if ($table === 'price_import_sessions') {
                foreach (['raw_rows', 'file_hash'] as $column) {
                    if (Schema::hasColumn($table, $column) && $this->columnIsNullable($table, $column)) {
                        $extra[$column] = null;
                    }
                }
            }

            $rows = $query->select('id')->get();
            foreach ($rows as $row) {
                $update = $extra;
                $locatorWasCleared = false;
                foreach ($existingColumns as $column) {
                    if ($this->columnIsNullable($table, $column)) {
                        $update[$column] = null;
                        $locatorWasCleared = true;
                    }
                }

                if ($locatorWasCleared
                    && Schema::hasColumn($table, 'storage_disk')
                    && $this->columnIsNullable($table, 'storage_disk')) {
                    $update['storage_disk'] = null;
                }

                if ($update === []) {
                    continue;
                }

                $updateQuery = DB::table($table)->where('id', $row->id)
                    ->where(function ($query) use ($update) {
                        foreach (array_keys($update) as $column) {
                            $query->orWhereNotNull($column);
                        }
                    });

                $changed += $updateQuery->update($update);
            }
        }

        return $changed;
    }

    private function columnIsNullable(string $table, string $column): bool
    {
        foreach (Schema::getColumns($table) as $metadata) {
            if (($metadata['name'] ?? null) === $column) {
                return (bool) ($metadata['nullable'] ?? false);
            }
        }

        return false;
    }
}
