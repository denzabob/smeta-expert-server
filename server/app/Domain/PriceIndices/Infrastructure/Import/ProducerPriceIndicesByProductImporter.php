<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use App\Domain\PriceIndices\Application\Contracts\StatisticalSourceImporter;
use App\Domain\PriceIndices\Application\Data\ImportExecutionResult;
use App\Domain\PriceIndices\Application\Data\ImportPreviewResult;
use App\Domain\PriceIndices\Application\Data\ObservationCandidate;
use App\Domain\PriceIndices\Application\Services\CleanupFailedStatisticalImport;
use App\Domain\PriceIndices\Application\Services\ResolveClassifierItem;
use App\Domain\PriceIndices\Application\Services\ResolveStatisticalSeries;
use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\CommodityCodeKind;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportIssueSeverity;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProducerPriceIndicesByProductImporter implements StatisticalSourceImporter
{
    public function __construct(
        private readonly ProducerPriceIndicesWorkbookScanner $scanner,
        private readonly ResolveClassifierItem $classifierResolver,
        private readonly ResolveStatisticalSeries $seriesResolver,
        private readonly StatisticalNameNormalizer $nameNormalizer,
        private readonly CleanupFailedStatisticalImport $cleanup,
    ) {
    }

    public function code(): string
    {
        return (string) config('price_indices.imports.importers.producer_price_indices_by_product.code');
    }

    public function version(): string
    {
        return (string) config('price_indices.imports.importers.producer_price_indices_by_product.version');
    }

    public function supports(StatisticalDataset $dataset, StatisticalSourceFile $file): bool
    {
        return $dataset->code === 'producer_price_indices_by_product'
            && $file->dataset_id === $dataset->id
            && $file->status === SourceFileStatus::Active;
    }

    public function preview(StatisticalSourceFile $file): ImportPreviewResult
    {
        $dataset = $file->dataset()->firstOrFail();
        if (! $this->supports($dataset, $file)) {
            throw new PriceIndicesInvariantViolation('Preview requires an active supported source file.');
        }

        $result = $this->scanner->scan($this->path($file));
        $supported = array_values(array_filter($result->sheets, fn ($sheet): bool => $sheet->supported));
        $ignored = array_values(array_filter($result->sheets, fn ($sheet): bool => ! $sheet->supported));

        return new ImportPreviewResult(
            [
                'filename' => $file->original_filename,
                'hash' => $file->sha256,
                'importer_code' => $this->code(),
                'importer_version' => $this->version(),
            ],
            [
                'sheets_total' => count($result->sheets),
                'supported_sheets' => array_map(fn ($sheet): array => $sheet->toArray(), $supported),
                'ignored_sheets' => array_map(fn ($sheet): array => $sheet->toArray(), $ignored),
                'detected_years' => $result->years,
                'comparison_bases' => array_values(array_unique(array_filter(array_map(fn ($sheet) => $sheet->comparisonBasis, $result->sheets)))),
                'topologies' => array_values(array_unique(array_filter(array_map(fn ($sheet) => $sheet->topology, $supported)))),
                'issue_samples' => array_slice(array_values(array_filter(
                    $result->issues,
                    fn (array $issue): bool => $issue['severity'] === StatisticalImportIssueSeverity::Fatal->value,
                )), 0, 10),
            ],
            $result->counts + [
                'issue_codes' => array_count_values(array_column($result->issues, 'code')),
                'elapsed_seconds' => $result->elapsedSeconds,
                'peak_memory_bytes' => $result->peakMemoryBytes,
                'chunk_rows' => $result->chunkRows,
            ],
            $result->samples,
        );
    }

    public function import(StatisticalImport $import): ImportExecutionResult
    {
        $startedAt = microtime(true);
        $import = $import->fresh(['dataset', 'sourceFile']);
        if ($import === null
            || $import->status !== StatisticalImportStatus::Importing
            || ! $this->supports($import->dataset, $import->sourceFile)
            || $import->importer_code !== $this->code()
            || $import->importer_version !== $this->version()
        ) {
            throw new PriceIndicesInvariantViolation('Importer requires a matching importing attempt and active source file.');
        }

        $indicator = StatisticalIndicator::query()
            ->where('dataset_id', $import->dataset_id)
            ->where('code', 'producer_price_index')
            ->sole();
        $territory = StatisticalTerritory::query()->where('code', 'RU')->sole();
        $batchSize = max(1, (int) config('price_indices.imports.db_batch_size', 500));
        $buffer = [];
        $persisted = 0;
        $dbInsertSeconds = 0.0;
        $classifierCache = [];
        $seriesCache = [];
        $runtimeIssues = [];
        $nameWarningCodes = [];

        $flush = function () use (
            &$buffer,
            &$persisted,
            &$dbInsertSeconds,
            &$classifierCache,
            &$seriesCache,
            &$runtimeIssues,
            &$nameWarningCodes,
            $import,
            $indicator,
            $territory,
        ): void {
            if ($buffer === []) {
                return;
            }
            $insertStartedAt = microtime(true);
            DB::transaction(function () use (
                &$buffer,
                &$classifierCache,
                &$seriesCache,
                &$runtimeIssues,
                &$nameWarningCodes,
                $import,
                $indicator,
                $territory,
            ): void {
                $rows = [];
                foreach ($buffer as $candidate) {
                    $classifierKey = $candidate->itemCode.'|'.$this->nameNormalizer->normalize($candidate->itemName);
                    $resolved = $classifierCache[$classifierKey] ??= $this->classifierResolver->execute(
                        $import->dataset,
                        'okpd2_based',
                        $candidate->itemCode,
                        $candidate->itemName,
                    );
                    if ($candidate->codeKind === CommodityCodeKind::RosstatLocalAg) {
                        $metadata = $resolved->item->metadata_json ?? [];
                        $storedKind = $metadata['provider_code_kind'] ?? null;
                        if ($storedKind !== null && $storedKind !== $candidate->codeKind->value) {
                            throw new PriceIndicesInvariantViolation('Classifier item provider code kind conflicts with the parsed item code.');
                        }
                        if ($storedKind === null) {
                            $resolved->item->forceFill([
                                'metadata_json' => $metadata + ['provider_code_kind' => $candidate->codeKind->value],
                            ])->save();
                        }
                    }
                    if ($resolved->nameChanged && ! isset($nameWarningCodes[$candidate->itemCode])) {
                        $nameWarningCodes[$candidate->itemCode] = true;
                        $runtimeIssues[] = [
                            'severity' => StatisticalImportIssueSeverity::Warning->value,
                            'code' => 'classifier_name_changed',
                            'message' => 'Observed classifier name differs from the stored canonical name.',
                            'sheet_name' => $candidate->sheetName,
                            'source_row' => $candidate->sourceRow,
                            'source_column' => null,
                            'classifier_item_code' => $candidate->itemCode,
                            'details_json' => ['observed_name' => $candidate->itemName, 'stored_name' => $resolved->item->name],
                        ];
                    }
                    $series = $seriesCache[$candidate->itemCode] ??= $this->seriesResolver->execute(
                        $import->dataset,
                        $indicator,
                        $resolved->item,
                        $territory,
                        'monthly',
                        'previous_month',
                        'percent',
                    );
                    $rows[] = [
                        'public_id' => (string) Str::uuid(),
                        'import_id' => $import->id,
                        'series_id' => $series->id,
                        'period_start' => $candidate->periodStart,
                        'value' => $candidate->value,
                        'missing_reason' => $candidate->missingReason,
                        'source_file_id' => $import->source_file_id,
                        'sheet_name' => $candidate->sheetName,
                        'source_row' => $candidate->sourceRow,
                        'source_column' => $candidate->sourceColumn,
                        'source_cell_address' => $candidate->sourceCellAddress,
                        'source_value_raw' => mb_substr($candidate->sourceValueRaw, 0, 255),
                        'footnote_marker' => $candidate->footnoteMarker,
                        'metadata_json' => null,
                        'created_at' => now(),
                    ];
                }
                DB::table('statistical_observations')->insert($rows);
            });
            $persisted += count($buffer);
            $buffer = [];
            $dbInsertSeconds += microtime(true) - $insertStartedAt;
        };

        try {
            $scan = $this->scanner->scan(
                $this->path($import->sourceFile),
                function (ObservationCandidate $candidate) use (&$buffer, $batchSize, $flush): void {
                    $buffer[] = $candidate;
                    if (count($buffer) >= $batchSize) {
                        $flush();
                    }
                },
                function (array $progress) use ($import, $flush, &$persisted): void {
                    $flush();
                    $import->forceFill([
                        'rows_scanned' => (int) $progress['rows_scanned'],
                        'observations_parsed' => (int) $progress['observation_candidates'],
                        'observations_valid' => $persisted,
                        'warnings_count' => (int) $progress['warnings'],
                        'errors_count' => (int) $progress['fatal_errors'],
                        'metadata_json' => [
                            'current_sheet' => $progress['sheet'],
                            'current_row' => $progress['end_row'],
                        ],
                    ])->save();
                    Log::info('Price indices import chunk processed.', $this->logContext($import) + [
                        'sheet' => $progress['sheet'],
                        'chunk_start' => $progress['start_row'],
                        'chunk_end' => $progress['end_row'],
                        'counts' => ['observations' => $persisted, 'errors' => $progress['fatal_errors']],
                        'elapsed' => $progress['elapsed_seconds'],
                    ]);
                },
            );
            $flush();
            $issues = array_merge($scan->issues, $runtimeIssues);
            $this->persistIssues($import, $issues);

            if ((int) $scan->counts['fatal_errors'] > 0) {
                throw new StatisticalImportParsingFailed('workbook_validation_failed', 'Workbook contains fatal import issues.');
            }
            $this->validatePersistedImport($import, $persisted, $indicator->id, $territory->id);

            $warnings = (int) $scan->counts['warnings'] + count($runtimeIssues);
            $summary = [
                'sheets_processed' => (int) $scan->counts['supported_sheets'],
                'years' => $scan->years,
                'commodity_occurrences' => (int) $scan->counts['commodity_occurrences'],
                'unique_items' => (int) $scan->counts['unique_commodity_codes'],
                'observations_created' => $persisted,
                'ordinary_numeric' => (int) $scan->counts['numeric'],
                'special_footnoted' => (int) $scan->counts['special_footnoted'],
                'missing' => (int) $scan->counts['missing'],
                'warnings' => $warnings,
                'errors' => 0,
                'chunk_rows' => $scan->chunkRows,
                'db_batch_size' => $batchSize,
                'workbook_parse_seconds' => $scan->elapsedSeconds,
                'db_insert_seconds' => $dbInsertSeconds,
                'peak_memory_bytes' => $scan->peakMemoryBytes,
            ];
            $import->forceFill([
                'rows_scanned' => (int) $scan->counts['rows_scanned'],
                'observations_parsed' => (int) $scan->counts['observation_candidates'],
                'observations_valid' => $persisted,
                'observations_rejected' => 0,
                'warnings_count' => $warnings,
                'errors_count' => 0,
                'validation_summary_json' => $summary,
                'metadata_json' => ['progress_percent' => 100],
            ])->save();

            return new ImportExecutionResult(
                $import->public_id,
                $this->code(),
                $this->version(),
                (int) $scan->counts['supported_sheets'],
                $scan->years,
                (int) $scan->counts['commodity_occurrences'],
                (int) $scan->counts['unique_commodity_codes'],
                $persisted,
                (int) $scan->counts['missing'],
                $warnings,
                0,
                microtime(true) - $startedAt,
                $scan->peakMemoryBytes,
                $scan->chunkRows,
                $batchSize,
                $scan->elapsedSeconds,
                $dbInsertSeconds,
            );
        } catch (Throwable $exception) {
            $this->cleanup->execute($import);
            throw $exception;
        }
    }

    private function path(StatisticalSourceFile $file): string
    {
        if (! Storage::disk($file->storage_disk)->exists($file->stored_path)) {
            throw new StatisticalImportParsingFailed('source_file_missing', 'Stored source file is missing.');
        }

        return Storage::disk($file->storage_disk)->path($file->stored_path);
    }

    /** @param list<array<string, mixed>> $issues */
    private function persistIssues(StatisticalImport $import, array $issues): void
    {
        foreach (array_chunk($issues, 500) as $chunk) {
            $now = now();
            DB::table('statistical_import_issues')->insert(array_map(fn (array $issue): array => [
                'public_id' => (string) Str::uuid(),
                'import_id' => $import->id,
                ...$issue,
                'details_json' => isset($issue['details_json']) ? json_encode($issue['details_json'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
                'created_at' => $now,
            ], $chunk));
        }
    }

    private function validatePersistedImport(StatisticalImport $import, int $persisted, int $indicatorId, int $territoryId): void
    {
        if ($persisted < 1) {
            throw new StatisticalImportParsingFailed('no_observations', 'Import produced no observations.');
        }

        $invalid = DB::table('statistical_observations as o')
            ->join('statistical_series as s', 's.id', '=', 'o.series_id')
            ->where('o.import_id', $import->id)
            ->where(function ($query) use ($import, $indicatorId, $territoryId): void {
                $query->where('o.source_file_id', '!=', $import->source_file_id)
                    ->orWhere('s.dataset_id', '!=', $import->dataset_id)
                    ->orWhere('s.indicator_id', '!=', $indicatorId)
                    ->orWhere('s.territory_id', '!=', $territoryId)
                    ->orWhere('s.frequency', '!=', 'monthly')
                    ->orWhere('s.comparison_basis', '!=', 'previous_month')
                    ->orWhere('s.unit', '!=', 'percent');
            })
            ->exists();

        if ($invalid) {
            throw new StatisticalImportParsingFailed('persisted_observation_invariant', 'Persisted observations violate import dimensions.');
        }
    }

    /** @return array<string, mixed> */
    private function logContext(StatisticalImport $import): array
    {
        return [
            'import_public_id' => $import->public_id,
            'source_file_public_id' => $import->sourceFile->public_id,
            'dataset_code' => $import->dataset->code,
            'importer_code' => $import->importer_code,
            'importer_version' => $import->importer_version,
        ];
    }
}
