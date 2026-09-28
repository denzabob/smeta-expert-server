<?php

namespace App\Observers;

use App\Services\Storage\StorageFileReferences;
use App\Services\Storage\StorageUsageService;
use App\Jobs\DeleteAccountStorageFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class StorageFileReferenceObserver
{
    public function saving(Model $model): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('storage_files')) { return; }
        $locators = app(StorageFileReferences::class)->locators($model);
        if (count($locators) !== 1) { return; }
        $file = DB::table('storage_files')->where($locators[0])->where('status', 'active')->first();
        if ($file === null) { return; }
        foreach (['size_bytes', 'file_size', 'size'] as $column) {
            if (array_key_exists($column, $model->getAttributes())) {
                $model->setAttribute($column, (int) $file->size_bytes);
            }
        }
    }

    public function saved(Model $model): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('storage_files')) { return; }
        app(StorageFileReferences::class)->sync($model);
    }

    public function deleted(Model $model): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('storage_files')) { return; }
        $module = $model instanceof \App\Models\Expert\ExpertProjectMaterial ? 'expert' : 'smeta';
        $files = app(StorageUsageService::class)->unlink($module, $model->getTable(), $model->getKey());
        if ($files !== []) {
            DB::afterCommit(fn () => DeleteAccountStorageFiles::enqueue());
        }
    }
}
