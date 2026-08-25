<?php

namespace App\Console\Commands;

use App\Domain\PriceIndices\Application\Services\ReportClassifierItemMappings;
use App\Domain\PriceIndices\Domain\Exceptions\ClassifierItemMappingException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Throwable;

class ReportStatisticalClassifierItemMappings extends Command
{
    protected $signature = 'price-indices:classifier:map-report
        {classifier : Canonical classifier code}
        {--limit=25 : Maximum number of ambiguous or unmapped rows (1-100)}';

    protected $description = 'Report version-scoped classifier mapping aggregates and bounded conflicts without changing data';

    public function handle(ReportClassifierItemMappings $reporter): int
    {
        try {
            $report = $reporter->execute(
                (string) $this->argument('classifier'),
                (int) $this->option('limit'),
            );
        } catch (ClassifierItemMappingException $exception) {
            $this->components->error("[{$exception->errorCode}] {$exception->safeMessage}");

            return SymfonyCommand::FAILURE;
        } catch (Throwable) {
            $this->components->error('[classifier_mapping_report_failure] The mapping report could not be generated safely.');

            return SymfonyCommand::FAILURE;
        }

        $this->table(['Field', 'Value'], [
            ['classifier', $report->classifierCode],
            ['active_version_public_id', $report->activeVersionPublicId],
            ['active_version_label', $report->activeVersionLabel],
            ['total_compatible_local_items', (string) $report->totalCompatibleItems],
            ['mapped_items', (string) $report->mappedItems],
            ['manual_decisions', (string) $report->manualDecisions],
            ['mapping_types', $this->counts($report->mappingTypes)],
            ['review_statuses', $this->counts($report->reviewStatuses)],
            ['conflict_limit', (string) $report->conflictLimit],
        ]);

        if ($report->conflicts !== []) {
            $this->table([
                'Local code',
                'Local name',
                'Canonical candidate code',
                'Canonical candidate name',
                'Mapping type',
                'Review status',
                'Reason',
            ], array_map(fn (array $row): array => [
                $row['local_code'],
                $row['local_name'],
                $row['canonical_code'] ?? 'none',
                $row['canonical_name'] ?? 'none',
                $row['mapping_type'],
                $row['review_status'],
                $row['reason'],
            ], $report->conflicts));
        }

        return SymfonyCommand::SUCCESS;
    }

    /** @param array<string, int> $counts */
    private function counts(array $counts): string
    {
        ksort($counts);

        return implode(', ', array_map(
            fn (string $key, int $count): string => "{$key}={$count}",
            array_keys($counts),
            array_values($counts),
        ));
    }
}
