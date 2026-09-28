<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageUsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecalculateStorageUsage extends Command
{
    protected $signature = 'storage:recalculate-usage {--user=} {--all}';
    protected $description = 'Rebuild account storage projections from the registry without accessing S1';

    public function handle(StorageUsageService $usage): int
    {
        $user = $this->option('user');
        if (($user !== null) === (bool) $this->option('all') || ($user !== null && (!ctype_digit((string) $user) || (int) $user < 1))) {
            $this->error('Specify exactly one of --user=<id> or --all.');
            return self::FAILURE;
        }
        try {
            $query = DB::table('users')->select('id')->orderBy('id');
            if ($user !== null) {
                $query->where('id', (int) $user);
            }
            $count = 0;
            $query->chunkById(200, function ($rows) use ($usage, &$count): void {
                foreach ($rows as $row) {
                    $usage->recalculate((int) $row->id);
                    $count++;
                }
            });
            $this->info('Storage users recalculated: '.$count);
            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('Storage usage recalculation failed', ['exception' => $e]);
            $this->error('STORAGE_USAGE_RECALCULATION_FAILED');
            return self::FAILURE;
        }
    }
}
