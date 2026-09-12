<?php

namespace App\Console\Commands;

use App\Services\Expert\ExpertStorageCleanupService;
use Illuminate\Console\Command;

class CleanupExpertStorageCommand extends Command
{
    protected $signature = 'expert:cleanup-storage {--limit=100 : Maximum number of due cleanup tasks}';

    protected $description = 'Retry deferred cleanup of private Expert storage files.';

    public function handle(ExpertStorageCleanupService $cleanup): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1 || $limit > 1000) {
            $this->error('The --limit value must be between 1 and 1000.');

            return self::FAILURE;
        }

        $result = $cleanup->retryDue($limit);

        $this->info("Processed {$result['processed']}; removed {$result['removed']}; deferred {$result['failed']}.");

        return self::SUCCESS;
    }
}
