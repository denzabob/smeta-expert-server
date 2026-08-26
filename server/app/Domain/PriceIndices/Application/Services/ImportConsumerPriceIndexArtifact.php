<?php

namespace App\Domain\PriceIndices\Application\Services;

use App\Domain\PriceIndices\Application\Contracts\CompletesStatisticalImportAtomically;
use App\Domain\PriceIndices\Application\Data\ConsumerPriceIndexImportResult;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Infrastructure\Import\ConsumerPriceIndicesWorkbookScanner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportConsumerPriceIndexArtifact
{
    public function __construct(
        private readonly StatisticalImporterRegistry $registry,
        private readonly CreateStatisticalImport $create,
        private readonly StartStatisticalImport $start,
        private readonly CleanupFailedStatisticalImport $cleanup,
        private readonly FailStatisticalImport $fail,
    ) {}

    public function execute(
        StatisticalSourceFile $sourceFile,
        ?User $actor = null,
    ): ConsumerPriceIndexImportResult {
        [$import, $reused] = DB::transaction(function () use ($sourceFile, $actor): array {
            $file = StatisticalSourceFile::query()
                ->with('dataset')
                ->lockForUpdate()
                ->findOrFail($sourceFile->id);
            if ($file->status !== SourceFileStatus::Active) {
                throw new PriceIndicesInvariantViolation('Controlled CPI import requires an active source file.');
            }

            $importer = $this->registry->forSourceFile($file);
            if (! $importer instanceof CompletesStatisticalImportAtomically) {
                throw new PriceIndicesInvariantViolation('Controlled CPI import resolved an incompatible importer.');
            }

            $attempts = StatisticalImport::query()
                ->where('dataset_id', $file->dataset_id)
                ->where('source_file_id', $file->id)
                ->where('importer_code', $importer->code())
                ->where('importer_version', $importer->version());
            $successful = (clone $attempts)
                ->whereIn('status', [
                    StatisticalImportStatus::ReadyForPublish,
                    StatisticalImportStatus::Published,
                    StatisticalImportStatus::Superseded,
                ])
                ->latest('attempt_no')
                ->lockForUpdate()
                ->first();
            if ($successful !== null) {
                return [$successful, true];
            }

            $latest = (clone $attempts)->latest('attempt_no')->lockForUpdate()->first();
            if ($latest !== null && $latest->status !== StatisticalImportStatus::Failed) {
                throw new PriceIndicesInvariantViolation('An equivalent CPI import attempt is already running.');
            }

            return [
                $this->create->execute($file->dataset, $file, $actor, $latest),
                false,
            ];
        });

        if ($reused) {
            return $this->result($import, true);
        }

        $import = $this->start->execute($import);
        try {
            $importer = $this->registry->forImport($import);
            if (! $importer instanceof CompletesStatisticalImportAtomically) {
                throw new PriceIndicesInvariantViolation('Controlled CPI import identity changed before execution.');
            }
            $importer->import($import);
        } catch (StatisticalImportParsingFailed $exception) {
            $this->markFailed($import, $exception->failureCode, $exception->getMessage());
            throw $exception;
        } catch (Throwable $exception) {
            $this->markFailed($import, 'unexpected_import_error', 'Unexpected statistical import failure.');
            throw $exception;
        }

        return $this->result($import->refresh(), false);
    }

    private function markFailed(StatisticalImport $import, string $code, string $message): void
    {
        $target = $import->refresh();
        if (! in_array($target->status, [
            StatisticalImportStatus::Importing,
            StatisticalImportStatus::Validating,
        ], true)) {
            return;
        }

        $this->cleanup->execute($target);
        $this->fail->execute($target, $code, $message);
    }

    private function result(StatisticalImport $import, bool $reused): ConsumerPriceIndexImportResult
    {
        $import->loadMissing(['dataset', 'sourceFile']);
        if (! in_array($import->status, [
            StatisticalImportStatus::ReadyForPublish,
            StatisticalImportStatus::Published,
            StatisticalImportStatus::Superseded,
        ], true)) {
            throw new PriceIndicesInvariantViolation('A CPI result requires a successful immutable import.');
        }

        $facts = DB::table('statistical_observations')
            ->where('import_id', $import->id)
            ->selectRaw(
                'COUNT(*) as observations_count, COUNT(DISTINCT series_id) as series_count, '
                .'MIN(period_start) as first_period, MAX(period_start) as last_period'
            )
            ->first();
        if ($facts === null
            || (int) $facts->observations_count < 1
            || ! is_string($facts->first_period)
            || ! is_string($facts->last_period)
        ) {
            throw new PriceIndicesInvariantViolation('A successful CPI import has incomplete persisted facts.');
        }

        $parser = $import->metadata_json['parser'] ?? [];

        return new ConsumerPriceIndexImportResult(
            $import->dataset->code,
            $import->sourceFile->public_id,
            strtoupper($import->sourceFile->sha256),
            $import->public_id,
            $import->status->value,
            $reused,
            (int) $facts->series_count,
            (int) $facts->observations_count,
            $facts->first_period,
            $facts->last_period,
            is_string($parser['code'] ?? null)
                ? $parser['code']
                : ConsumerPriceIndicesWorkbookScanner::PARSER_CODE,
            is_string($parser['version'] ?? null)
                ? $parser['version']
                : ConsumerPriceIndicesWorkbookScanner::PARSER_VERSION,
            $import->importer_code,
            $import->importer_version,
        );
    }
}
