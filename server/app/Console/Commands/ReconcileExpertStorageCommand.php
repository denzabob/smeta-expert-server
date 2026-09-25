<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Expert\ExpertStorageUsageService;
use Illuminate\Console\Command;

final class ReconcileExpertStorageCommand extends Command
{
    protected $signature = 'expert:storage-reconcile
        {--user= : Reconcile one user id}
        {--all : Reconcile all users}
        {--dry-run : Report differences without changing the projection}';

    protected $description = 'Rebuild Expert original-file usage from material database records.';

    public function handle(ExpertStorageUsageService $usage): int
    {
        $userOption = $this->option('user');
        $all = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');

        if (($userOption !== null) === $all) {
            $this->error('Укажите ровно один режим: --user=<id> или --all.');

            return self::FAILURE;
        }

        if ($userOption !== null) {
            if (! ctype_digit((string) $userOption) || (int) $userOption < 1) {
                $this->error('Параметр --user должен содержать положительный id.');

                return self::FAILURE;
            }

            $user = User::withTrashed()->find((int) $userOption);
            if ($user === null) {
                $this->error('Пользователь не найден.');

                return self::FAILURE;
            }

            $reservations = $usage->releaseExpiredUploadReservations(1000, (int) $user->id, $dryRun);
            $result = $usage->reconcileUser($user, $dryRun);
            $this->reportResult($result);
            $this->line(sprintf('Истёкших reservation: проверено %d, освобождено %d.', $reservations['processed'], $reservations['released']));
            $this->info($dryRun ? 'Dry-run завершён. Projection не изменена.' : 'Пересчёт завершён.');

            return self::SUCCESS;
        }

        $reservations = $usage->releaseExpiredUploadReservations(10000, null, $dryRun);
        $summary = $usage->recalculateAll($dryRun, function (array $result): void {
            if ($result['changed']) {
                $this->reportResult($result);
            }
        });

        $this->info(sprintf(
            '%s: пользователей проверено %d, с расхождением %d.',
            $dryRun ? 'Dry-run' : 'Пересчёт',
            $summary['users_checked'],
            $summary['users_with_drift'],
        ));

        $this->line($dryRun
            ? 'Объём к корректировке: '.$summary['bytes_affected'].' байт.'
            : 'Скорректировано: '.$summary['bytes_affected'].' байт.');
        $this->line(sprintf('Истёкших reservation: проверено %d, освобождено %d.', $reservations['processed'], $reservations['released']));
        $this->line('Расхождение reserved_bytes: '.$summary['reserved_bytes_affected'].' байт.');

        if ($dryRun) {
            $this->line('Projection не изменена.');
        }

        return self::SUCCESS;
    }

    /** @param array<string, int|bool> $result */
    private function reportResult(array $result): void
    {
        $difference = $result['difference_bytes'] > 0
            ? '+'.$result['difference_bytes']
            : (string) $result['difference_bytes'];
        $countDifference = $result['difference_materials_count'] > 0
            ? '+'.$result['difference_materials_count']
            : (string) $result['difference_materials_count'];
        $status = $result['dry_run']
            ? 'dry-run'
            : ($result['changed'] ? 'corrected' : 'in sync');

        $this->line(sprintf(
            'User %d | recorded: %d B (%d files), reserved %d B | calculated: %d B (%d files), reserved %d B | difference: %s B (%s files), reserved %d B | %s',
            $result['user_id'],
            $result['recorded_bytes'],
            $result['recorded_materials_count'],
            $result['recorded_reserved_bytes'],
            $result['calculated_bytes'],
            $result['calculated_materials_count'],
            $result['calculated_reserved_bytes'],
            $difference,
            $countDifference,
            $result['difference_reserved_bytes'],
            $status,
        ));
    }
}
