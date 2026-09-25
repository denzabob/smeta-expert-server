<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Expert\ExpertStorageException;
use App\Services\Expert\ExpertStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckExpertStorageCommand extends Command
{
    protected $signature = 'expert:storage-check {--disk= : Check a specific configured disk instead of the Expert default}';

    protected $description = 'Check the configured Expert storage disk with a temporary private object.';

    public function handle(ExpertStorageService $storage): int
    {
        $diskOption = $this->option('disk');
        $disk = is_string($diskOption) && trim($diskOption) !== ''
            ? trim($diskOption)
            : trim((string) config('expert.storage.disk', 's1'));

        $this->line("Expert storage disk: {$disk}");

        try {
            if (! is_string($diskOption) || trim($diskOption) === '') {
                $disk = $storage->configuredDisk();
            }
            $storage->healthCheck($disk, fn (string $step) => $this->line($step));
            $this->info('Expert storage check: OK');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $failureCode = $exception instanceof ExpertStorageException
                ? $exception->failureCode
                : 'STORAGE_CHECK_FAILED';

            if (! $exception instanceof ExpertStorageException) {
                Log::warning('Expert storage health check failed.', [
                    'operation' => 'health_check',
                    'disk' => $disk,
                    'exception_class' => $exception::class,
                    'error_code' => $failureCode,
                ]);
            }

            $this->error("Expert storage check failed ({$failureCode}).");

            return self::FAILURE;
        }
    }
}
