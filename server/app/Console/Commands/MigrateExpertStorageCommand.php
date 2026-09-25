<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Expert\ExpertProject;
use App\Models\Expert\ExpertProjectMaterial;
use App\Models\User;
use App\Services\Expert\ExpertStorageException;
use App\Services\Expert\ExpertStorageMigrationService;
use Illuminate\Console\Command;
use Throwable;

final class MigrateExpertStorageCommand extends Command
{
    protected $signature = 'expert:storage-migrate
        {--dry-run : Show the plan without creating journal rows or writing S1}
        {--user= : Scope to a user id}
        {--project= : Scope to a project id or public id}
        {--material= : Scope to a material id or public id}
        {--all : Select all active local materials}
        {--limit=50 : Maximum materials in a write run (1-1000)}
        {--resume : Include journaled work whose Material still needs recovery}
        {--confirm-all : Required together with --all for a write run}';

    protected $description = 'Plan or run recoverable local-to-S1 Expert material migration.';

    public function handle(ExpertStorageMigrationService $migration): int
    {
        $scope = $this->resolveScope();
        if ($scope === null) {
            return self::FAILURE;
        }
        $filters = $scope['filters'];
        $all = $scope['all'];
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->positiveIntOption('limit', 50, 1000);
        if ($limit === null) {
            return self::FAILURE;
        }

        if ($dryRun) {
            try {
                $plan = $migration->plan($filters);
                $this->reportPlan($plan);
            } catch (Throwable $exception) {
                $code = $exception instanceof ExpertStorageException
                    ? $exception->failureCode
                    : (property_exists($exception, 'errorCode') ? (string) $exception->errorCode : 'MIGRATION_PLAN_FAILED');
                $this->error("Dry-run unavailable ({$code}). Check the Expert database migrations and storage configuration.");

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        if (! config('expert.storage_migration.enabled', false)) {
            $this->error('Запись миграции отключена. Для планового запуска задайте EXPERT_STORAGE_MIGRATION_ENABLED=true.');

            return self::FAILURE;
        }
        if ($all && ! $this->option('confirm-all')) {
            $this->error('Для массового запуска одновременно укажите --all и --confirm-all.');

            return self::FAILURE;
        }
        if (! $all && $this->option('confirm-all')) {
            $this->error('--confirm-all допустим только вместе с --all.');

            return self::FAILURE;
        }

        try {
            $result = $migration->migrate($filters, (bool) $this->option('resume'), $limit, function (int $done, int $total, array $outcome, int $processedBytes, int $selectedBytes): void {
                $this->line("Progress: {$done} / {$total} | {$processedBytes} / {$selectedBytes} bytes");
            });
        } catch (Throwable $exception) {
            $code = $exception instanceof ExpertStorageException
                ? $exception->failureCode
                : (property_exists($exception, 'errorCode') ? (string) $exception->errorCode : 'MIGRATION_PREFLIGHT_FAILED');
            $this->error("Migration stopped during preflight ({$code}). No Material pointer was switched.");

            return self::FAILURE;
        }

        $this->reportMigration($result);

        return $result['failed'] === 0 && $result['accounting_unchanged'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{filters: array<string, int>, all: bool}|null */
    private function resolveScope(): ?array
    {
        $provided = [];
        foreach (['user', 'project', 'material'] as $name) {
            $value = $this->option($name);
            if ($value !== null && trim((string) $value) !== '') {
                $provided[$name] = trim((string) $value);
            }
        }
        $all = (bool) $this->option('all');
        if (count($provided) + (int) $all !== 1) {
            $this->error('Укажите ровно один режим: --user, --project, --material или --all.');

            return null;
        }

        if (isset($provided['user'])) {
            if (! ctype_digit($provided['user']) || (int) $provided['user'] < 1
                || ! User::withTrashed()->whereKey((int) $provided['user'])->exists()) {
                $this->error('Параметр --user должен указывать на существующий user id.');

                return null;
            }

            return ['filters' => ['user_id' => (int) $provided['user']], 'all' => false];
        }
        if (isset($provided['project'])) {
            $project = ctype_digit($provided['project'])
                ? ExpertProject::query()->find((int) $provided['project'])
                : ExpertProject::query()->where('public_id', $provided['project'])->first();
            if ($project === null) {
                $this->error('Проект не найден.');

                return null;
            }

            return ['filters' => ['project_id' => (int) $project->id], 'all' => false];
        }
        if (isset($provided['material'])) {
            $material = ctype_digit($provided['material'])
                ? ExpertProjectMaterial::query()->find((int) $provided['material'])
                : ExpertProjectMaterial::query()->where('public_id', $provided['material'])->first();
            if ($material === null) {
                $this->error('Material не найден.');

                return null;
            }

            return ['filters' => ['material_id' => (int) $material->id], 'all' => false];
        }

        return ['filters' => [], 'all' => true];
    }

    private function positiveIntOption(string $name, int $default, int $max): ?int
    {
        $value = $this->option($name);
        $value = $value === null ? (string) $default : (string) $value;
        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > $max) {
            $this->error("Параметр --{$name} должен быть числом от 1 до {$max}.");

            return null;
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $plan */
    private function reportPlan(array $plan): void
    {
        $summary = $plan['summary'];
        $this->info('Dry-run: записи журнала и объекты S1 не изменялись.');
        foreach ([
            'Materials' => 'materials',
            'Files' => 'files',
            'Total size' => 'total_size',
            'Already S1' => 'already_s1',
            'Legacy local' => 'legacy_local',
            'Missing local' => 'missing_local',
            'Size mismatch' => 'size_mismatch',
            'Hash missing' => 'hash_missing',
            'Conflicts' => 'conflicts',
            'Source check failed' => 'source_check_failed',
            'Target check failed' => 'target_check_failed',
        ] as $label => $key) {
            $value = $summary[$key];
            $this->line($label.': '.($key === 'total_size' ? $this->formatBytes((int) $value) : $value));
        }
        if (! $summary['target_check_available']) {
            $this->line('S1 target checks: unavailable; dry-run remained read-only.');
        }
        foreach ($plan['users'] as $userId => $user) {
            $this->line("User {$userId} | files: {$user['files']} | size: ".$this->formatBytes((int) $user['size']));
        }
    }

    /** @param array<string, mixed> $result */
    private function reportMigration(array $result): void
    {
        $this->info(sprintf(
            'Selected: %d | Copied: %d | Verified: %d | Switched: %d | Failed: %d | Bytes copied: %s | Duration: %s s',
            $result['selected'], $result['copied'], $result['verified'], $result['switched'], $result['failed'],
            number_format((int) $result['bytes_copied']), $result['duration_seconds'],
        ));
        $before = $result['before']['selected_materials'];
        $after = $result['after']['selected_materials'];
        $allBefore = $result['before']['all_materials'];
        $allAfter = $result['after']['all_materials'];
        $this->line("All Material totals before/after: {$allBefore['files']} / {$allAfter['files']} files; {$allBefore['bytes']} / {$allAfter['bytes']} bytes.");
        $this->line("Selected Material totals before/after: {$before['files']} / {$after['files']} files; {$before['bytes']} / {$after['bytes']} bytes.");
        $this->line('Storage usage projection unchanged: '.($result['accounting_unchanged'] ? 'yes' : 'NO'));
        if (! $result['all_material_totals_unchanged']) {
            $this->line('All Material totals changed during the run; compare with concurrent uploads/deletes.');
        }

        foreach ($result['outcomes'] as $outcome) {
            if ($outcome['error_code'] !== null) {
                $this->line("Failed {$outcome['material_public_id']} | {$outcome['error_code']} | {$outcome['status']}");
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_000_000_000) {
            return number_format($bytes / 1_000_000_000, 2).' GB';
        }
        if ($bytes >= 1_000_000) {
            return number_format($bytes / 1_000_000, 2).' MB';
        }
        if ($bytes >= 1_000) {
            return number_format($bytes / 1_000, 2).' KB';
        }

        return $bytes.' B';
    }
}
