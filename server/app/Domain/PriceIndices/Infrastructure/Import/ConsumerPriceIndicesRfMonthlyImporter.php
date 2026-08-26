<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use App\Domain\PriceIndices\Application\Contracts\CompletesStatisticalImportAtomically;
use App\Domain\PriceIndices\Application\Data\ConsumerPriceIndexSourceNote;
use App\Domain\PriceIndices\Application\Data\ImportExecutionResult;
use App\Domain\PriceIndices\Application\Data\ImportPreviewResult;
use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexSeries;
use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexSnapshot;
use App\Domain\PriceIndices\Application\Services\BeginImportValidation;
use App\Domain\PriceIndices\Application\Services\ConsumerPriceIndexPersistenceObserver;
use App\Domain\PriceIndices\Application\Services\MarkImportReadyForPublish;
use App\Domain\PriceIndices\Application\Services\ResolveClassifierItem;
use App\Domain\PriceIndices\Application\Services\ResolveStatisticalSeries;
use App\Domain\PriceIndices\Application\Services\StatisticalNameNormalizer;
use App\Domain\PriceIndices\Domain\Classifiers\StatisticalClassifierItem;
use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportIssueSeverity;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportStatus;
use App\Domain\PriceIndices\Domain\Exceptions\PriceIndicesInvariantViolation;
use App\Domain\PriceIndices\Domain\Exceptions\SourceFileStorageException;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use App\Domain\PriceIndices\Domain\Imports\StatisticalImport;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\Series\StatisticalSeries;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use App\Domain\PriceIndices\Infrastructure\Storage\StreamingFileHasher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class ConsumerPriceIndicesRfMonthlyImporter implements CompletesStatisticalImportAtomically
{
    private const INDICATOR_CODE = 'consumer_price_index';

    private const INDICATOR_NAME = 'Индекс потребительских цен';

    private const TERRITORY_NAME = 'Российская Федерация';

    public function __construct(
        private readonly ConsumerPriceIndicesWorkbookScanner $scanner,
        private readonly ResolveClassifierItem $classifierResolver,
        private readonly ResolveStatisticalSeries $seriesResolver,
        private readonly StatisticalNameNormalizer $nameNormalizer,
        private readonly StreamingFileHasher $hasher,
        private readonly BeginImportValidation $beginValidation,
        private readonly MarkImportReadyForPublish $markReady,
        private readonly ConsumerPriceIndexPersistenceObserver $observer,
    ) {}

    public function code(): string
    {
        return (string) config('price_indices.imports.importers.consumer_price_indices_rf_monthly.code');
    }

    public function version(): string
    {
        return (string) config('price_indices.imports.importers.consumer_price_indices_rf_monthly.version');
    }

    public function supports(StatisticalDataset $dataset, StatisticalSourceFile $file): bool
    {
        return $dataset->code === ParsedConsumerPriceIndexSnapshot::DATASET_CODE
            && $dataset->provider_code === 'rosstat'
            && $dataset->data_kind === 'index'
            && $dataset->frequency === ParsedConsumerPriceIndexSnapshot::FREQUENCY
            && $dataset->classifier_code === ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE
            && $file->dataset_id === $dataset->id
            && $file->status === SourceFileStatus::Active;
    }

    public function preview(StatisticalSourceFile $file): ImportPreviewResult
    {
        $dataset = $file->dataset()->firstOrFail();
        if (! $this->supports($dataset, $file)) {
            throw new PriceIndicesInvariantViolation('Preview requires an active supported CPI source file.');
        }

        $snapshot = $this->scanner->scan($this->verifiedPath($file));
        $years = $this->years($snapshot);

        return new ImportPreviewResult(
            [
                'filename' => $file->original_filename,
                'hash' => $file->sha256,
                'importer_code' => $this->code(),
                'importer_version' => $this->version(),
                'parser_code' => ConsumerPriceIndicesWorkbookScanner::PARSER_CODE,
                'parser_version' => ConsumerPriceIndicesWorkbookScanner::PARSER_VERSION,
            ],
            [
                'sheets_total' => 5,
                'supported_sheets' => array_map(
                    fn (ParsedConsumerPriceIndexSeries $series): array => [
                        'name' => $series->sheetName,
                        'item_code' => $series->internalKey,
                        'item_name' => $series->name,
                        'topology' => 'cpi_aggregate_monthly',
                    ],
                    $snapshot->series,
                ),
                'ignored_sheets' => [['name' => 'Содержание', 'reason' => 'metadata_only']],
                'detected_years' => $years,
                'comparison_bases' => [ParsedConsumerPriceIndexSnapshot::COMPARISON_BASIS],
                'topologies' => ['cpi_aggregate_monthly'],
                'issue_samples' => $snapshot->warnings,
            ],
            [
                'commodity_occurrences' => count($snapshot->series),
                'unique_commodity_codes' => count($snapshot->series),
                'observation_candidates' => $snapshot->totalObservations(),
                'numeric' => $snapshot->totalObservations(),
                'missing' => 0,
                'special_footnoted' => 0,
                'warnings' => count($snapshot->warnings),
                'fatal_errors' => 0,
            ],
            array_map(
                fn (ParsedConsumerPriceIndexSeries $series): array => [
                    'item_code' => $series->internalKey,
                    'item_name' => $series->name,
                    ...$series->observations[0]->toArray(),
                ],
                $snapshot->series,
            ),
        );
    }

    public function import(StatisticalImport $import): ImportExecutionResult
    {
        $startedAt = microtime(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $import = $import->fresh(['dataset', 'sourceFile.source']);
        if ($import === null
            || $import->status !== StatisticalImportStatus::Importing
            || ! $this->supports($import->dataset, $import->sourceFile)
            || $import->importer_code !== $this->code()
            || $import->importer_version !== $this->version()
        ) {
            throw new PriceIndicesInvariantViolation(
                'CPI importer requires a matching importing attempt and active source file.'
            );
        }

        $path = $this->verifiedPath($import->sourceFile);
        $parseStartedAt = microtime(true);
        $snapshot = $this->scanner->scan($path);
        $parseSeconds = microtime(true) - $parseStartedAt;
        $this->verifiedPath($import->sourceFile);
        $batchSize = max(1, (int) config('price_indices.imports.db_batch_size', 500));

        return DB::transaction(function () use (
            $import,
            $snapshot,
            $startedAt,
            $parseSeconds,
            $batchSize,
        ): ImportExecutionResult {
            $target = StatisticalImport::query()
                ->with(['dataset', 'sourceFile.source'])
                ->lockForUpdate()
                ->findOrFail($import->id);
            if ($target->status !== StatisticalImportStatus::Importing
                || ! $this->supports($target->dataset, $target->sourceFile)
                || $target->importer_code !== $this->code()
                || $target->importer_version !== $this->version()
            ) {
                throw new PriceIndicesInvariantViolation('The CPI import changed before persistence.');
            }

            $indicator = $this->resolveIndicator($target->dataset);
            $territory = $this->resolveTerritory();
            $items = $this->resolveItems($target->dataset, $snapshot);
            $this->observer->reached(ConsumerPriceIndexPersistenceObserver::AFTER_REFERENCE_RESOLUTION);

            $series = $this->resolveSeries($target->dataset, $indicator, $territory, $items);
            $this->observer->reached(ConsumerPriceIndexPersistenceObserver::AFTER_SERIES_RESOLUTION);

            $dbInsertStartedAt = microtime(true);
            $persisted = $this->persistObservations($target, $snapshot, $series, $batchSize);
            $dbInsertSeconds = microtime(true) - $dbInsertStartedAt;
            $this->persistWarnings($target, $snapshot);
            $this->validatePersistedSnapshot($target, $snapshot, $series, $indicator, $territory);

            $warnings = count($snapshot->warnings);
            $target->forceFill([
                'rows_scanned' => $persisted,
                'observations_parsed' => $persisted,
                'observations_valid' => $persisted,
                'observations_rejected' => 0,
                'warnings_count' => $warnings,
                'errors_count' => 0,
                'validation_summary_json' => [
                    'series_count' => count($snapshot->series),
                    'observations_created' => $persisted,
                    'observations_per_series' => $snapshot->observationsPerSeries(),
                    'coverage' => [
                        'first_period' => $snapshot->firstPeriod(),
                        'last_period' => $snapshot->lastPeriod(),
                    ],
                    'dimensions' => [
                        'territory' => ParsedConsumerPriceIndexSnapshot::TERRITORY_CODE,
                        'frequency' => ParsedConsumerPriceIndexSnapshot::FREQUENCY,
                        'comparison_basis' => ParsedConsumerPriceIndexSnapshot::COMPARISON_BASIS,
                        'unit' => ParsedConsumerPriceIndexSnapshot::UNIT,
                    ],
                    'warnings' => $warnings,
                ],
                'metadata_json' => $this->importMetadata($target->sourceFile, $snapshot),
            ])->save();

            $this->observer->reached(
                ConsumerPriceIndexPersistenceObserver::BEFORE_READY_TRANSITION,
                $persisted,
            );
            $validating = $this->beginValidation->execute($target->refresh());
            $ready = $this->markReady->execute($validating);

            return new ImportExecutionResult(
                $ready->public_id,
                $this->code(),
                $this->version(),
                count($snapshot->series),
                $this->years($snapshot),
                count($snapshot->series),
                count($items),
                $persisted,
                0,
                $warnings,
                0,
                microtime(true) - $startedAt,
                memory_get_peak_usage(true),
                $persisted,
                $batchSize,
                $parseSeconds,
                $dbInsertSeconds,
            );
        });
    }

    private function verifiedPath(StatisticalSourceFile $file): string
    {
        $disk = Storage::disk($file->storage_disk);
        if (! $disk->exists($file->stored_path)) {
            throw new StatisticalImportParsingFailed('source_file_missing', 'Stored CPI source file is missing.');
        }

        $path = $disk->path($file->stored_path);
        try {
            $actual = $this->hasher->hash($path);
        } catch (SourceFileStorageException $exception) {
            throw new StatisticalImportParsingFailed(
                'source_file_unreadable',
                'Stored CPI source file cannot be read.',
                $exception,
            );
        }

        if ($actual->size !== $file->file_size
            || ! hash_equals(strtolower($file->sha256), strtolower($actual->sha256))
        ) {
            throw new StatisticalImportParsingFailed(
                'source_file_integrity_mismatch',
                'Stored CPI source file no longer matches its registered size and SHA-256.'
            );
        }

        return $path;
    }

    private function resolveIndicator(StatisticalDataset $dataset): StatisticalIndicator
    {
        $indicator = StatisticalIndicator::query()->firstOrCreate(
            ['dataset_id' => $dataset->id, 'code' => self::INDICATOR_CODE],
            ['name' => self::INDICATOR_NAME, 'data_kind' => 'index'],
        );
        if ($indicator->data_kind !== 'index') {
            throw new PriceIndicesInvariantViolation('The CPI indicator identity conflicts with stored data.');
        }

        return $indicator;
    }

    private function resolveTerritory(): StatisticalTerritory
    {
        $territory = StatisticalTerritory::query()->firstOrCreate(
            ['code' => ParsedConsumerPriceIndexSnapshot::TERRITORY_CODE],
            [
                'name' => self::TERRITORY_NAME,
                'normalized_name' => $this->nameNormalizer->normalize(self::TERRITORY_NAME),
                'type' => 'country',
            ],
        );
        if ($territory->name !== self::TERRITORY_NAME || $territory->type !== 'country') {
            throw new PriceIndicesInvariantViolation('The CPI territory identity conflicts with stored data.');
        }

        return $territory;
    }

    /**
     * @return array<string, StatisticalClassifierItem>
     */
    private function resolveItems(
        StatisticalDataset $dataset,
        ParsedConsumerPriceIndexSnapshot $snapshot,
    ): array {
        $items = [];
        foreach ($snapshot->series as $parsedSeries) {
            $resolved = $this->classifierResolver->execute(
                $dataset,
                ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE,
                $parsedSeries->internalKey,
                $parsedSeries->name,
            );
            $item = $resolved->item;
            if ($resolved->nameChanged || $item->parent_item_id !== null) {
                throw new PriceIndicesInvariantViolation('The CPI dataset-local item identity conflicts with stored data.');
            }

            $metadata = $item->metadata_json ?? [];
            if (($metadata['identity_scope'] ?? 'dataset_local') !== 'dataset_local'
                || ($metadata['official_provider_code'] ?? false) !== false
            ) {
                throw new PriceIndicesInvariantViolation('The CPI item is incorrectly identified as an official provider code.');
            }
            if ($item->metadata_json === null) {
                $item->forceFill([
                    'metadata_json' => [
                        'identity_scope' => 'dataset_local',
                        'official_provider_code' => false,
                    ],
                ])->save();
            }
            $items[$parsedSeries->internalKey] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, StatisticalClassifierItem>  $items
     * @return array<string, StatisticalSeries>
     */
    private function resolveSeries(
        StatisticalDataset $dataset,
        StatisticalIndicator $indicator,
        StatisticalTerritory $territory,
        array $items,
    ): array {
        $series = [];
        foreach ($items as $key => $item) {
            $series[$key] = $this->seriesResolver->execute(
                $dataset,
                $indicator,
                $item,
                $territory,
                ParsedConsumerPriceIndexSnapshot::FREQUENCY,
                ParsedConsumerPriceIndexSnapshot::COMPARISON_BASIS,
                ParsedConsumerPriceIndexSnapshot::UNIT,
            );
        }

        return $series;
    }

    /**
     * @param  array<string, StatisticalSeries>  $series
     */
    private function persistObservations(
        StatisticalImport $import,
        ParsedConsumerPriceIndexSnapshot $snapshot,
        array $series,
        int $batchSize,
    ): int {
        $rows = [];
        $persisted = 0;
        $now = now();

        foreach ($snapshot->series as $parsedSeries) {
            foreach ($parsedSeries->observations as $observation) {
                $rows[] = [
                    'public_id' => (string) Str::uuid(),
                    'import_id' => $import->id,
                    'series_id' => $series[$parsedSeries->internalKey]->id,
                    'period_start' => $observation->periodStart,
                    'value' => $observation->value,
                    'missing_reason' => null,
                    'source_file_id' => $import->source_file_id,
                    'sheet_name' => $observation->sheetName,
                    'source_row' => $observation->sourceRow,
                    'source_column' => $observation->sourceColumn,
                    'source_cell_address' => $observation->sourceCellAddress,
                    'source_value_raw' => mb_substr($observation->sourceValueRaw, 0, 255),
                    'footnote_marker' => null,
                    'metadata_json' => null,
                    'created_at' => $now,
                ];

                if (count($rows) >= $batchSize) {
                    DB::table('statistical_observations')->insert($rows);
                    $persisted += count($rows);
                    $rows = [];
                    $this->observer->reached(
                        ConsumerPriceIndexPersistenceObserver::AFTER_OBSERVATION_BATCH,
                        $persisted,
                    );
                }
            }
        }

        if ($rows !== []) {
            DB::table('statistical_observations')->insert($rows);
            $persisted += count($rows);
            $this->observer->reached(
                ConsumerPriceIndexPersistenceObserver::AFTER_OBSERVATION_BATCH,
                $persisted,
            );
        }

        return $persisted;
    }

    private function persistWarnings(
        StatisticalImport $import,
        ParsedConsumerPriceIndexSnapshot $snapshot,
    ): void {
        if ($snapshot->warnings === []) {
            return;
        }

        $now = now();
        DB::table('statistical_import_issues')->insert(array_map(
            fn (array $warning): array => [
                'public_id' => (string) Str::uuid(),
                'import_id' => $import->id,
                'severity' => StatisticalImportIssueSeverity::Warning->value,
                'code' => $warning['code'],
                'message' => $warning['message'],
                'sheet_name' => null,
                'source_row' => null,
                'source_column' => null,
                'classifier_item_code' => null,
                'details_json' => null,
                'created_at' => $now,
            ],
            $snapshot->warnings,
        ));
    }

    /**
     * @param  array<string, StatisticalSeries>  $series
     */
    private function validatePersistedSnapshot(
        StatisticalImport $import,
        ParsedConsumerPriceIndexSnapshot $snapshot,
        array $series,
        StatisticalIndicator $indicator,
        StatisticalTerritory $territory,
    ): void {
        if (count($series) !== 4
            || $snapshot->totalObservations() < 1
            || DB::table('statistical_observations')->where('import_id', $import->id)->count()
                !== $snapshot->totalObservations()
        ) {
            throw new StatisticalImportParsingFailed(
                'persisted_cpi_count_mismatch',
                'Persisted CPI counts do not match the validated snapshot.'
            );
        }

        $itemCodes = array_keys($series);
        $datasetItems = StatisticalClassifierItem::query()
            ->where('dataset_id', $import->dataset_id)
            ->where('classifier_code', ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE);
        if ((clone $datasetItems)->count() !== 4
            || (clone $datasetItems)->whereIn('item_code', $itemCodes)->count() !== 4
        ) {
            throw new StatisticalImportParsingFailed(
                'persisted_cpi_item_mismatch',
                'Persisted CPI items do not match the validated snapshot.'
            );
        }

        foreach ($snapshot->series as $parsedSeries) {
            $count = DB::table('statistical_observations')
                ->where('import_id', $import->id)
                ->where('series_id', $series[$parsedSeries->internalKey]->id)
                ->count();
            if ($count !== count($parsedSeries->observations)) {
                throw new StatisticalImportParsingFailed(
                    'persisted_cpi_series_count_mismatch',
                    'A persisted CPI series does not match its validated observation count.'
                );
            }
        }

        $coverage = DB::table('statistical_observations')
            ->where('import_id', $import->id)
            ->selectRaw('MIN(period_start) as first_period, MAX(period_start) as last_period')
            ->first();
        if (($coverage->first_period ?? null) !== $snapshot->firstPeriod()
            || ($coverage->last_period ?? null) !== $snapshot->lastPeriod()
        ) {
            throw new StatisticalImportParsingFailed(
                'persisted_cpi_coverage_mismatch',
                'Persisted CPI coverage does not match the validated snapshot.'
            );
        }

        $invalid = DB::table('statistical_observations as observations')
            ->join('statistical_series as series', 'series.id', '=', 'observations.series_id')
            ->where('observations.import_id', $import->id)
            ->where(function ($query) use ($import, $indicator, $territory): void {
                $query->where('observations.source_file_id', '!=', $import->source_file_id)
                    ->orWhere('series.dataset_id', '!=', $import->dataset_id)
                    ->orWhere('series.indicator_id', '!=', $indicator->id)
                    ->orWhere('series.territory_id', '!=', $territory->id)
                    ->orWhere('series.frequency', '!=', ParsedConsumerPriceIndexSnapshot::FREQUENCY)
                    ->orWhere('series.comparison_basis', '!=', ParsedConsumerPriceIndexSnapshot::COMPARISON_BASIS)
                    ->orWhere('series.unit', '!=', ParsedConsumerPriceIndexSnapshot::UNIT);
            })
            ->exists();
        if ($invalid) {
            throw new StatisticalImportParsingFailed(
                'persisted_cpi_dimension_mismatch',
                'Persisted CPI observations violate the validated dimensions.'
            );
        }
    }

    /** @return list<int> */
    private function years(ParsedConsumerPriceIndexSnapshot $snapshot): array
    {
        $years = [];
        foreach ($snapshot->series[0]->observations as $observation) {
            $years[(int) substr($observation->periodStart, 0, 4)] = true;
        }

        return array_keys($years);
    }

    /** @return array<string, mixed> */
    private function importMetadata(
        StatisticalSourceFile $sourceFile,
        ParsedConsumerPriceIndexSnapshot $snapshot,
    ): array {
        $sourceMetadata = $sourceFile->metadata_json ?? [];

        return [
            'parser' => [
                'code' => ConsumerPriceIndicesWorkbookScanner::PARSER_CODE,
                'version' => ConsumerPriceIndicesWorkbookScanner::PARSER_VERSION,
            ],
            'importer' => ['code' => $this->code(), 'version' => $this->version()],
            'source' => [
                'landing_url' => $sourceFile->source?->source_page_url ?? ($sourceMetadata['landing_url'] ?? null),
                'source_url' => $sourceFile->source_url,
                'filename' => $sourceFile->original_filename,
                'mime_type' => $sourceFile->mime_type,
                'bytes' => $sourceFile->file_size,
                'sha256' => strtoupper($sourceFile->sha256),
                'workbook_modified' => $sourceMetadata['workbook_modified'] ?? null,
                'displayed_update_date' => $sourceMetadata['displayed_update_date']
                    ?? $snapshot->sourceUpdatedLabel,
                'acquisition_method' => $sourceFile->acquisition_method->value,
                'trust' => $sourceMetadata['trust'] ?? null,
                'context' => $sourceMetadata['context'] ?? null,
            ],
            'coverage' => [
                'first_period' => $snapshot->firstPeriod(),
                'last_period' => $snapshot->lastPeriod(),
                'series_count' => count($snapshot->series),
                'observation_count' => $snapshot->totalObservations(),
            ],
            'source_notes' => array_map(
                fn (ConsumerPriceIndexSourceNote $note): array => $note->toArray(),
                $snapshot->sourceNotes,
            ),
            'progress_percent' => 100,
        ];
    }
}
