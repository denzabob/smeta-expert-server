<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** FK cascades do not emit Eloquent child events; reconcile business links in the same delete transaction. */
final class StorageCascadeReferences
{
    public function prune(?int $userId = null): void
    {
        if (!Schema::hasTable('storage_files')) { return; }
        $sources = array_map(fn ($class) => (new $class())->getTable(), array_keys(StorageFileReferences::SOURCES));
        $sources[] = 'revision_run_items';
        $removed = false;
        foreach (array_unique($sources) as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            $query = DB::table('storage_file_links as links')->join('storage_files as files', 'files.id', '=', 'links.storage_file_id')
                ->where('links.module', 'smeta')->where('links.source_type', $table)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($table)->whereColumn($table . '.id', 'links.source_id'));
            if ($userId !== null) {
                $query->where('files.user_id', $userId);
            }
            foreach ($query->select('links.*')->get() as $link) {
                $removed = app(StorageUsageService::class)->unlink('smeta', $table, $link->source_id) !== [] || $removed;
            }
        }
        if ($removed) {
            DB::afterCommit(fn () => \App\Jobs\DeleteAccountStorageFiles::enqueue());
        }
    }
}
