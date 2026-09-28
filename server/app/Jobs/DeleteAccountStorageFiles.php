<?php

namespace App\Jobs;

use App\Services\Storage\ObjectStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DeleteAccountStorageFiles implements ShouldQueue
{
    use Queueable;

    public static function enqueue(): void
    {
        try {
            // A sync queue or unavailable queue backend must not turn an already
            // committed business operation into an upload compensation.
            dispatch(new self());
        } catch (Throwable $e) {
            Log::error('Account storage delete dispatch failed.', ['exception_class' => $e::class]);
            // The deleting rows remain durable; the scheduled job retries them.
        }
    }

    public function handle(ObjectStorage $objects): void
    {
        $failed = false;
        foreach (DB::table('storage_files')->where('status', 'deleting')->orderBy('id')->limit(100)->get() as $file) {
            try {
                if ($objects->delete($file->disk, $file->path)) {
                    DB::table('storage_files')->where('id', $file->id)->where('status', 'deleting')
                        ->update(['status' => 'deleted', 'deleted_at' => now(), 'updated_at' => now()]);
                } else {
                    throw new \RuntimeException('Storage deletion returned false.');
                }
            } catch (Throwable $e) {
                $failed = true;
                Log::error('Account storage physical delete failed.', ['storage_file_id' => $file->id, 'exception_class' => $e::class]);
            }
        }
        if ($failed) {
            throw new \RuntimeException('STORAGE_DELETE_FAILED');
        }
    }
}
