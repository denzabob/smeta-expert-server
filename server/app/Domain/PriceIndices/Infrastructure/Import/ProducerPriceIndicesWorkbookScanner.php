<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use App\Domain\PriceIndices\Application\Data\ObservationCandidate;
use App\Domain\PriceIndices\Application\Data\SheetClassification;
use App\Domain\PriceIndices\Application\Data\WorkbookScanResult;
use App\Domain\PriceIndices\Domain\Enums\StatisticalImportIssueSeverity;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProducerPriceIndicesWorkbookScanner
{
    private const CONTROL_ITEM_CODE = '31.02.10.140';

    public function __construct(
        private readonly StatisticalYearDetector $yearDetector,
        private readonly StatisticalComparisonBasisDetector $basisDetector,
        private readonly StatisticalMonthHeaderParser $monthParser,
        private readonly CommodityCodeParser $commodityCodeParser,
        private readonly StatisticalTerritoryDetector $territoryDetector,
        private readonly StatisticalNumericValueNormalizer $numericNormalizer,
    ) {
    }

    /**
     * @param null|callable(ObservationCandidate): void $onCandidate
     * @param null|callable(array<string, int|float|string>): void $onChunk
     */
    public function scan(
        string $path,
        ?callable $onCandidate = null,
        ?callable $onChunk = null,
        ?int $chunkRows = null,
        ?int $sampleLimit = null,
    ): WorkbookScanResult {
        $startedAt = microtime(true);
        $chunkRows ??= (int) config('price_indices.imports.chunk_rows', 2_000);
        $sampleLimit ??= (int) config('price_indices.imports.preview_sample_limit', 50);
        if ($chunkRows < 1) {
            throw new InvalidArgumentException('Chunk size must be positive.');
        }

        $reader = $this->reader();
        $worksheetInfo = $reader->listWorksheetInfo($path);
        if (count($worksheetInfo) > (int) config('price_indices.imports.max_sheets', 64)) {
            throw new StatisticalImportParsingFailed('workbook_limits_exceeded', 'Workbook sheet limit exceeded.');
        }

        $rawClassifications = [];
        $headerReader = $this->reader();
        $headerReader->setReadFilter(new XlsxChunkReadFilter(1, 100));
        $headerWorkbook = $headerReader->load($path);
        foreach ($worksheetInfo as $position => $info) {
            $rawClassifications[] = $this->classifySheet(
                $headerWorkbook->getSheetByName((string) $info['worksheetName'])
                    ?? throw new StatisticalImportParsingFailed('missing_sheet', 'Expected worksheet was not loaded.'),
                $position + 1,
                (int) $info['totalRows'],
                (int) $info['totalColumns'],
            );
        }
        $headerWorkbook->disconnectWorksheets();
        unset($headerWorkbook);
        gc_collect_cycles();

        $detectedYears = array_values(array_unique(array_filter(array_column($rawClassifications, 'year'))));
        sort($detectedYears);
        $lastDetectedYear = $detectedYears === [] ? null : max($detectedYears);
        $classifications = [];
        $issues = [];

        foreach ($rawClassifications as $raw) {
            $candidate = $raw['year'] !== null
                && $raw['year'] >= 2021
                && ($lastDetectedYear === null || $raw['year'] <= $lastDetectedYear)
                && $raw['month_columns'] !== [];
            $supported = $candidate
                && $raw['comparison_basis'] === 'previous_month'
                && $raw['topology'] !== null
                && $raw['errors'] === [];
            $ignoredReason = null;

            if (! $supported) {
                $ignoredReason = $raw['ignored_reason']
                    ?? ($raw['comparison_basis'] !== 'previous_month' ? 'unsupported_comparison_basis' : null)
                    ?? ($raw['year'] === null || $raw['year'] < 2021 ? 'unsupported_year' : null)
                    ?? 'unsupported_structure';
            }

            if ($candidate && ($raw['comparison_basis'] === null || $raw['topology'] === null || $raw['errors'] !== [])) {
                foreach ($raw['errors'] ?: ['unsupported_structure'] as $code) {
                    $issues[] = $this->issue(StatisticalImportIssueSeverity::Fatal, $code, $raw['name']);
                }
            } elseif (! $supported) {
                $issues[] = $this->issue(
                    StatisticalImportIssueSeverity::Informational,
                    'ignored_sheet',
                    $raw['name'],
                    details: ['reason' => $ignoredReason],
                );
            }

            $classifications[] = new SheetClassification(
                $raw['name'],
                $raw['position'],
                $raw['total_rows'],
                $raw['year'],
                $raw['comparison_basis'],
                $raw['topology'],
                $raw['header_row'],
                $raw['month_columns'],
                $supported,
                $ignoredReason,
                $raw['errors'],
            );
        }

        $counts = [
            'sheets_total' => count($classifications),
            'supported_sheets' => count(array_filter($classifications, fn (SheetClassification $sheet): bool => $sheet->supported)),
            'ignored_sheets' => count(array_filter($classifications, fn (SheetClassification $sheet): bool => ! $sheet->supported)),
            'rows_scanned' => 0,
            'commodity_occurrences' => 0,
            'unique_commodity_codes' => 0,
            'unique_commodity_codes_lexical' => 0,
            'numeric_code_occurrences' => 0,
            'rosstat_local_ag_code_occurrences' => 0,
            'observation_candidates' => 0,
            'numeric' => 0,
            'special_footnoted' => 0,
            'missing' => 0,
            'ignored_territory_rows' => 0,
            'warnings' => 0,
            'fatal_errors' => count(array_filter($issues, fn (array $issue): bool => $issue['severity'] === StatisticalImportIssueSeverity::Fatal->value)),
        ];
        $uniqueCodes = [];
        $lexicalCodes = [];
        $observationKeys = [];
        $samples = [];
        $controlSamples = [];

        foreach ($classifications as $classification) {
            if (! $classification->supported) {
                continue;
            }

            if (count($classification->monthColumns) < 12 && $classification->year === $lastDetectedYear) {
                $issues[] = $this->issue(
                    StatisticalImportIssueSeverity::Warning,
                    'partial_current_year',
                    $classification->name,
                    details: ['months' => array_values($classification->monthColumns)],
                );
                $counts['warnings']++;
            }

            $currentCommodity = null;
            $currentHasRu = false;
            $dataStart = ((int) $classification->headerRow) + 1;
            $maxRows = (int) config('price_indices.imports.max_rows_per_sheet', 30_000);
            if ($classification->totalRows > $maxRows) {
                $issues[] = $this->issue(StatisticalImportIssueSeverity::Fatal, 'sheet_row_limit_exceeded', $classification->name);
                $counts['fatal_errors']++;
                continue;
            }

            for ($chunkStart = $dataStart; $chunkStart <= $classification->totalRows; $chunkStart += $chunkRows) {
                $chunkEnd = min($classification->totalRows, $chunkStart + $chunkRows - 1);
                $worksheet = $this->loadSheetChunk($path, $classification->name, $chunkStart, $chunkEnd);
                $chunkStartedAt = microtime(true);

                for ($row = $chunkStart; $row <= $chunkEnd; $row++) {
                    $counts['rows_scanned']++;
                    $name = trim((string) $worksheet->getCell("A{$row}")->getValue());
                    $rawCode = (string) $worksheet->getCell("B{$row}")->getValue();
                    $parsedCode = $this->commodityCodeParser->parse($rawCode);

                    if ($parsedCode !== null) {
                        $code = $parsedCode->normalizedCode;
                        if ($classification->topology === 'regional' && $currentCommodity !== null && ! $currentHasRu) {
                            $issues[] = $this->issue(
                                StatisticalImportIssueSeverity::Fatal,
                                'missing_ru_territory',
                                $classification->name,
                                $currentCommodity['row'],
                                classifierItemCode: $currentCommodity['code'],
                            );
                            $counts['fatal_errors']++;
                        }
                        if ($name === '') {
                            $issues[] = $this->issue(
                                StatisticalImportIssueSeverity::Fatal,
                                'missing_commodity_name',
                                $classification->name,
                                $row,
                                classifierItemCode: $code,
                            );
                            $counts['fatal_errors']++;
                            $currentCommodity = null;
                            continue;
                        }

                        $currentCommodity = [
                            'code' => $code,
                            'code_kind' => $parsedCode->codeKind,
                            'name' => $name,
                            'row' => $row,
                        ];
                        $currentHasRu = false;
                        $counts['commodity_occurrences']++;
                        $counts[$parsedCode->codeKind->value.'_code_occurrences']++;
                        $uniqueCodes[$code] = true;
                        $lexicalCodes[$rawCode] = true;

                        if ($classification->topology === 'flat') {
                            $this->emitMonths(
                                $worksheet,
                                $classification,
                                $row,
                                $currentCommodity,
                                $counts,
                                $issues,
                                $observationKeys,
                                $samples,
                                $controlSamples,
                                $sampleLimit,
                                $onCandidate,
                            );
                            $currentHasRu = true;
                        }
                        continue;
                    }

                    if ($classification->topology !== 'regional' || $name === '') {
                        continue;
                    }

                    if ($currentCommodity === null) {
                        continue;
                    }

                    if ($this->territoryDetector->isRussianFederation($name)) {
                        $currentHasRu = true;
                        $this->emitMonths(
                            $worksheet,
                            $classification,
                            $row,
                            $currentCommodity,
                            $counts,
                            $issues,
                            $observationKeys,
                            $samples,
                            $controlSamples,
                            $sampleLimit,
                            $onCandidate,
                        );
                    } else {
                        $counts['ignored_territory_rows']++;
                    }
                }

                if ($onChunk !== null) {
                    $onChunk([
                        'sheet' => $classification->name,
                        'start_row' => $chunkStart,
                        'end_row' => $chunkEnd,
                        'elapsed_seconds' => microtime(true) - $chunkStartedAt,
                        ...$counts,
                    ]);
                }

                $worksheet->getParent()?->disconnectWorksheets();
                unset($worksheet);
                gc_collect_cycles();
            }

            if ($classification->topology === 'regional' && $currentCommodity !== null && ! $currentHasRu) {
                $issues[] = $this->issue(
                    StatisticalImportIssueSeverity::Fatal,
                    'missing_ru_territory',
                    $classification->name,
                    $currentCommodity['row'],
                    classifierItemCode: $currentCommodity['code'],
                );
                $counts['fatal_errors']++;
            }
        }

        $counts['unique_commodity_codes'] = count($uniqueCodes);
        $counts['unique_commodity_codes_lexical'] = count($lexicalCodes);
        $samples = array_values(array_slice($samples, 0, $sampleLimit));
        foreach ($controlSamples as $sample) {
            $key = $sample['item_code'].'|'.$sample['period_start'];
            $existing = array_filter($samples, fn (array $candidate): bool => ($candidate['item_code'].'|'.$candidate['period_start']) === $key);
            if ($existing === []) {
                $samples[] = $sample;
            }
        }

        return new WorkbookScanResult(
            $classifications,
            $counts,
            $issues,
            $samples,
            array_values(array_unique(array_map(
                fn (SheetClassification $sheet): int => (int) $sheet->year,
                array_filter($classifications, fn (SheetClassification $sheet): bool => $sheet->supported),
            ))),
            microtime(true) - $startedAt,
            memory_get_peak_usage(true),
            $chunkRows,
        );
    }

    /** @return array<string, mixed> */
    private function classifySheet(Worksheet $worksheet, int $position, int $totalRows, int $totalColumns): array
    {
        $name = $worksheet->getTitle();
        $headerText = [];
        for ($row = 1; $row <= min(4, $totalRows); $row++) {
            for ($column = 1; $column <= min(20, $totalColumns); $column++) {
                $value = trim((string) $worksheet->getCell([$column, $row])->getValue());
                if ($value !== '') {
                    $headerText[] = $value;
                }
            }
        }
        $joinedHeader = implode(' ', $headerText);
        $year = $this->yearDetector->detect($joinedHeader);
        $basis = $this->basisDetector->detect($joinedHeader);
        $errors = [];
        if ($this->yearDetector->isAmbiguous($joinedHeader)) {
            $errors[] = 'ambiguous_year';
        }

        $headerRow = null;
        $monthColumns = [];
        for ($row = 1; $row <= min(10, $totalRows); $row++) {
            $cells = [];
            for ($column = 3; $column <= min(20, $totalColumns); $column++) {
                $cells[Coordinate::stringFromColumnIndex($column)] = $worksheet->getCell([$column, $row])->getValue();
            }
            try {
                $parsed = $this->monthParser->parse($cells);
            } catch (InvalidArgumentException $exception) {
                $errors[] = $exception->getMessage();
                continue;
            }
            if ($parsed !== []) {
                $headerRow = $row;
                $monthColumns = $parsed;
                break;
            }
        }

        $topology = null;
        if ($headerRow !== null) {
            for ($row = $headerRow + 1; $row <= min(100, $totalRows); $row++) {
                $code = $this->commodityCodeParser->parse($worksheet->getCell("B{$row}")->getValue());
                if ($code === null) {
                    continue;
                }
                $hasSameRowValue = false;
                foreach (array_keys($monthColumns) as $column) {
                    if ($worksheet->getCell("{$column}{$row}")->getValue() !== null) {
                        $hasSameRowValue = true;
                        break;
                    }
                }
                $topology = $hasSameRowValue ? 'flat' : 'regional';
                break;
            }
        }

        if ($topology === 'flat' && ! $this->territoryDetector->titleImpliesRussianFederation($joinedHeader)) {
            $errors[] = 'missing_ru_territory';
        }

        return [
            'name' => $name,
            'position' => $position,
            'total_rows' => $totalRows,
            'year' => $year,
            'comparison_basis' => $basis,
            'topology' => $topology,
            'header_row' => $headerRow,
            'month_columns' => $monthColumns,
            'ignored_reason' => $monthColumns === [] ? 'missing_month_header' : null,
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /**
     * @param array{code:string,code_kind:\App\Domain\PriceIndices\Domain\Enums\CommodityCodeKind,name:string,row:int} $commodity
     * @param array<string, int|float> $counts
     * @param list<array<string, mixed>> $issues
     * @param array<string, array<string, mixed>> $observationKeys
     * @param list<array<string, mixed>> $samples
     * @param array<int, array<string, mixed>> $controlSamples
     * @param null|callable(ObservationCandidate): void $onCandidate
     */
    private function emitMonths(
        Worksheet $worksheet,
        SheetClassification $classification,
        int $valueRow,
        array $commodity,
        array &$counts,
        array &$issues,
        array &$observationKeys,
        array &$samples,
        array &$controlSamples,
        int $sampleLimit,
        ?callable $onCandidate,
    ): void {
        foreach ($classification->monthColumns as $column => $month) {
            $cell = $worksheet->getCell("{$column}{$valueRow}");
            try {
                $normalized = $this->numericNormalizer->normalize(
                    $cell->getValue(),
                    $cell->getDataType(),
                    $cell->getStyle()->getNumberFormat()->getFormatCode(),
                );
            } catch (StatisticalImportParsingFailed $exception) {
                $issues[] = $this->issue(
                    StatisticalImportIssueSeverity::Fatal,
                    $exception->failureCode,
                    $classification->name,
                    $valueRow,
                    $column,
                    $commodity['code'],
                    ['cell' => "{$column}{$valueRow}"],
                );
                $counts['fatal_errors']++;
                continue;
            }

            $period = sprintf('%04d-%02d-01', $classification->year, $month);
            $key = $commodity['code'].'|'.$period;
            if (isset($observationKeys[$key])) {
                $issues[] = $this->issue(
                    StatisticalImportIssueSeverity::Fatal,
                    'duplicate_observation',
                    $classification->name,
                    $valueRow,
                    $column,
                    $commodity['code'],
                    ['previous' => $observationKeys[$key], 'current' => ['sheet' => $classification->name, 'row' => $valueRow]],
                );
                $counts['fatal_errors']++;
                continue;
            }
            $observationKeys[$key] = [
                'sheet' => $classification->name,
                'row' => $valueRow,
                'item_name' => $commodity['name'],
            ];

            $candidate = new ObservationCandidate(
                $commodity['code'],
                $commodity['code_kind'],
                $commodity['name'],
                'RU',
                $period,
                $normalized->value,
                $normalized->missingReason?->value,
                $classification->name,
                $valueRow,
                $column,
                "{$column}{$valueRow}",
                $normalized->raw,
                $normalized->footnoteMarker,
            );
            $counts['observation_candidates']++;
            if ($normalized->missingReason !== null) {
                $counts['missing']++;
                $counts['warnings']++;
                $issues[] = $this->issue(
                    StatisticalImportIssueSeverity::Warning,
                    'missing_value',
                    $classification->name,
                    $valueRow,
                    $column,
                    $commodity['code'],
                    ['missing_reason' => $normalized->missingReason->value],
                );
            } elseif ($normalized->specialFootnoted) {
                $counts['special_footnoted']++;
                $counts['warnings']++;
                $issues[] = $this->issue(
                    StatisticalImportIssueSeverity::Warning,
                    'footnoted_numeric',
                    $classification->name,
                    $valueRow,
                    $column,
                    $commodity['code'],
                    ['footnote_marker' => $normalized->footnoteMarker],
                );
            } else {
                $counts['numeric']++;
            }

            $sample = $candidate->toArray();
            if (count($samples) < $sampleLimit) {
                $samples[] = $sample;
            }
            if ($commodity['code'] === self::CONTROL_ITEM_CODE && ! isset($controlSamples[$classification->year])) {
                $controlSamples[$classification->year] = $sample;
            }
            if ($onCandidate !== null) {
                $onCandidate($candidate);
            }
        }
    }

    private function loadSheetChunk(string $path, string $sheetName, int $startRow, int $endRow): Worksheet
    {
        $reader = $this->reader();
        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new XlsxChunkReadFilter($startRow, $endRow));

        return $reader->load($path)->getSheetByName($sheetName)
            ?? throw new StatisticalImportParsingFailed('missing_sheet', 'Expected worksheet was not loaded.');
    }

    private function reader(): Xlsx
    {
        $reader = new Xlsx();
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);

        return $reader;
    }

    /** @return array<string, mixed> */
    private function issue(
        StatisticalImportIssueSeverity $severity,
        string $code,
        string $sheet,
        ?int $row = null,
        ?string $column = null,
        ?string $classifierItemCode = null,
        array $details = [],
    ): array {
        return [
            'severity' => $severity->value,
            'code' => $code,
            'message' => str_replace('_', ' ', $code),
            'sheet_name' => $sheet,
            'source_row' => $row,
            'source_column' => $column,
            'classifier_item_code' => $classifierItemCode,
            'details_json' => $details === [] ? null : $details,
        ];
    }
}
