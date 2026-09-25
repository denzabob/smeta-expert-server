<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Expert\ExpertStorageMigrationService;
use Illuminate\Console\Command;
use Throwable;

final class CleanupExpertStorageMigrationCommand extends Command
{
    protected $signature = 'expert:storage-migration-cleanup {--limit=100 : Maximum due source files to process (1-1000)}';

    protected $description = 'Remove verified local migration sources after the configured grace period.';

    public function handle(ExpertStorageMigrationService $migration): int
    {
        $value = (string) ($this->option('limit') ?? '100');
        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 1000) {
            $this->error('Параметр --limit должен быть числом от 1 до 1000.');

            return self::FAILURE;
        }

        try {
            $result = $migration->cleanupSources((int) $value);
        } catch (Throwable $exception) {
            $code = property_exists($exception, 'errorCode') ? (string) $exception->errorCode : 'SOURCE_CLEANUP_FAILED';
            $this->error("Cleanup не выполнен ({$code}).");

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Processed %d; removed %d; failed %d; skipped %d.',
            $result['processed'], $result['removed'], $result['failed'], $result['skipped'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
