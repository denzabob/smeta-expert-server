<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageUsageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditStorageUsage extends Command
{
    protected $signature = 'storage:usage-audit {--user=} {--all}';
    protected $description = 'Read-only comparison of account storage projections with registry totals';

    public function handle(StorageUsageService $usage): int
    {
        $user = $this->option('user');
        if (($user !== null && (bool) $this->option('all')) || ($user !== null && (!ctype_digit((string) $user) || (int) $user < 1))) {
            $this->error('Specify --user=<id> or --all, not both.');
            return self::FAILURE;
        }
        try {
            $query = DB::table('users')->select('id')->orderBy('id');
            if ($user !== null) {
                $query->where('id', (int) $user);
            }
            $mismatches = 0;
            $duplicates = 0;
            $counts = ['used' => 0, 'reserved' => 0, 'module' => 0, 'category' => 0];
            $query->chunkById(200, function ($rows) use ($usage, &$mismatches, &$duplicates, &$counts): void {
                foreach ($rows as $row) {
                    $audit = $usage->audit((int) $row->id);
                    $mismatches += count($audit['mismatches']);
                    $duplicates += $audit['duplicate_objects'];
                    foreach (array_keys($audit['mismatches']) as $field) {
                        if ($field === 'used_bytes') {
                            $counts['used']++;
                        } elseif ($field === 'reserved_bytes') {
                            $counts['reserved']++;
                        } elseif (str_starts_with($field, 'module:')) {
                            $counts['module']++;
                        } else {
                            $counts['category']++;
                        }
                    }
                    if ($audit['mismatches']) {
                        $this->line(json_encode($audit, JSON_THROW_ON_ERROR));
                    }
                }
            });
            $this->info("Usage mismatches: {$mismatches}; duplicate objects: {$duplicates}");
            $this->info("Used mismatches: {$counts['used']}; reserved mismatches: {$counts['reserved']}; module mismatches: {$counts['module']}; category mismatches: {$counts['category']}");
            return $mismatches === 0 && $duplicates === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            Log::error('Storage usage audit failed', ['exception' => $e]);
            $this->error('STORAGE_USAGE_AUDIT_FAILED');
            return self::FAILURE;
        }
    }
}
