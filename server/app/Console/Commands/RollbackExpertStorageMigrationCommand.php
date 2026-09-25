<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Expert\ExpertProjectMaterial;
use App\Services\Expert\ExpertStorageMigrationService;
use Illuminate\Console\Command;

final class RollbackExpertStorageMigrationCommand extends Command
{
    protected $signature = 'expert:storage-migration-rollback {--material= : Material id or public id}';

    protected $description = 'Switch one migrated Expert material back to its verified local source.';

    public function handle(ExpertStorageMigrationService $migration): int
    {
        $value = trim((string) $this->option('material'));
        if ($value === '') {
            $this->error('Укажите --material=<id|public_id>.');

            return self::FAILURE;
        }
        $material = ctype_digit($value)
            ? ExpertProjectMaterial::query()->find((int) $value)
            : ExpertProjectMaterial::query()->where('public_id', $value)->first();
        if ($material === null) {
            $this->error('Material не найден.');

            return self::FAILURE;
        }

        $result = $migration->rollback((int) $material->id);
        if (! $result['success']) {
            $this->error("Rollback не выполнен ({$result['error_code']}). Статус: {$result['status']}.");

            return self::FAILURE;
        }

        $this->info("Rollback завершён. Material: {$material->public_id}; статус: {$result['status']}. S1 object оставлен без изменений.");

        return self::SUCCESS;
    }
}
