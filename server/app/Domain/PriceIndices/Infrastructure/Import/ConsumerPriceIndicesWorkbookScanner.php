<?php

namespace App\Domain\PriceIndices\Infrastructure\Import;

use App\Domain\PriceIndices\Application\Data\ConsumerPriceIndexSourceNote;
use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexObservation;
use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexSeries;
use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexSnapshot;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

final class ConsumerPriceIndicesWorkbookScanner
{
    public const PARSER_CODE = 'consumer_price_indices_workbook';

    public const PARSER_VERSION = '1.0.0';

    /** @var list<string> */
    private const REQUIRED_SHEETS = ['Содержание', '01', '02', '03', '04'];

    /**
     * @var array<string, array{key: string, name: string, title_fragment: string, contents_number: string}>
     */
    private const CATEGORIES = [
        '01' => [
            'key' => 'all_items_and_services',
            'name' => 'Товары и услуги',
            'title_fragment' => 'товары и услуги',
            'contents_number' => '1.1',
        ],
        '02' => [
            'key' => 'food_products',
            'name' => 'Продовольственные товары',
            'title_fragment' => 'продовольственные товары',
            'contents_number' => '1.2',
        ],
        '03' => [
            'key' => 'non_food_products',
            'name' => 'Непродовольственные товары',
            'title_fragment' => 'непродовольственные товары',
            'contents_number' => '1.3',
        ],
        '04' => [
            'key' => 'services',
            'name' => 'Услуги',
            'title_fragment' => 'услуги',
            'contents_number' => '1.4',
        ],
    ];

    /** @var list<string> */
    private const MONTHS = [
        'январь',
        'февраль',
        'март',
        'апрель',
        'май',
        'июнь',
        'июль',
        'август',
        'сентябрь',
        'октябрь',
        'ноябрь',
        'декабрь',
    ];

    private const TERRITORIAL_NOTE = '*)Без учета статистической информации по Донецкой Народной Республике, Луганской Народной Республике, Запорожской и Херсонской областям.';

    private const DENOMINATION_NOTE_PATTERN = '/Обращаем Ваше внимание, что в январе 1998 г\. была проведена деноминация, в результате которой произошло уменьшение масштаба цен в 1000 раз\./u';

    public function scan(string $path): ParsedConsumerPriceIndexSnapshot
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->fail('source_file_missing', 'The CPI workbook is missing or unreadable.');
        }

        $book = $this->load($path);

        try {
            $this->validateSheetSet($book);

            $series = [];
            $expectedYears = null;
            $denominationNote = null;
            $territorialNote = null;
            $trailingBlankCount = 0;

            foreach (array_keys(self::CATEGORIES) as $sheetName) {
                $sheet = $book->getSheetByName($sheetName)
                    ?? $this->fail('missing_required_sheet', "Required CPI sheet {$sheetName} is missing.");
                $parsed = $this->parseSeries($sheet);

                if ($expectedYears === null) {
                    $expectedYears = $parsed['years'];
                } elseif ($expectedYears !== $parsed['years']) {
                    $this->fail('inconsistent_year_columns', 'CPI data sheets do not use the same year sequence.');
                }

                if ($denominationNote === null) {
                    $denominationNote = $parsed['denomination_note'];
                } elseif ($denominationNote !== $parsed['denomination_note']) {
                    $this->fail('inconsistent_denomination_note', 'CPI denomination notes differ between data sheets.');
                }

                if ($territorialNote === null) {
                    $territorialNote = $parsed['territorial_note'];
                } elseif ($territorialNote !== $parsed['territorial_note']) {
                    $this->fail('inconsistent_territorial_note', 'CPI territorial notes differ between data sheets.');
                }

                $trailingBlankCount += $parsed['trailing_blank_count'];
                $series[] = $parsed['series'];
            }

            $this->validateSharedCoverage($series);
            $contents = $book->getSheetByName('Содержание')
                ?? $this->fail('missing_required_sheet', 'Required CPI contents sheet is missing.');
            $this->validateContentsSheet($contents, $expectedYears ?? []);

            $notes = [
                new ConsumerPriceIndexSourceNote(
                    'january_1998_denomination',
                    (string) $denominationNote,
                    '01',
                    'A20',
                ),
                new ConsumerPriceIndexSourceNote(
                    'territorial_coverage_2026',
                    (string) $territorialNote,
                    '01',
                    'A22',
                ),
            ];
            $warnings = [[
                'code' => 'previous_december_excluded',
                'message' => 'Rows 18-19 use the unsupported previous-December basis and were not emitted.',
            ]];
            if ($trailingBlankCount > 0) {
                $warnings[] = [
                    'code' => 'trailing_blank_periods_excluded',
                    'message' => 'Trailing blank months after the last published period were not emitted.',
                ];
            }

            return new ParsedConsumerPriceIndexSnapshot(
                $series,
                $notes,
                $warnings,
                $this->plainText($contents, 'A17'),
            );
        } finally {
            $book->disconnectWorksheets();
        }
    }

    private function load(string $path): Spreadsheet
    {
        try {
            $reader = new Xlsx;
            $reader->setReadDataOnly(false);
            $reader->setReadEmptyCells(true);
            $reader->setIncludeCharts(false);

            return $reader->load($path);
        } catch (StatisticalImportParsingFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new StatisticalImportParsingFailed(
                'invalid_cpi_workbook',
                'The CPI workbook could not be opened as XLSX.',
                $exception,
            );
        }
    }

    private function validateSheetSet(Spreadsheet $book): void
    {
        if ($book->getSheetNames() !== self::REQUIRED_SHEETS) {
            $missing = array_values(array_diff(self::REQUIRED_SHEETS, $book->getSheetNames()));
            $code = $missing === [] ? 'unexpected_sheet_set' : 'missing_required_sheet';

            $this->fail($code, 'The CPI workbook must contain exactly the required sheets in their declared order.');
        }
    }

    /**
     * @return array{
     *     series: ParsedConsumerPriceIndexSeries,
     *     years: list<int>,
     *     denomination_note: string,
     *     territorial_note: string,
     *     trailing_blank_count: int
     * }
     */
    private function parseSeries(Worksheet $sheet): array
    {
        $years = $this->parseYears($sheet);
        $category = $this->resolveCategory($this->plainText($sheet, 'A1'), $years);
        if ($category['sheet'] !== $sheet->getTitle()) {
            $this->fail('aggregate_sheet_mismatch', "CPI aggregate title is assigned to the wrong structural sheet {$sheet->getTitle()}.");
        }
        if ($this->plainText($sheet, 'AI3') !== 'на конец периода, в %') {
            $this->fail('unexpected_unit_heading', "Unexpected CPI unit heading in sheet {$sheet->getTitle()}.");
        }
        if ($this->plainText($sheet, 'A5') !== 'к концу предыдущего месяца') {
            $this->fail('unexpected_comparison_basis', "Unexpected CPI comparison basis in sheet {$sheet->getTitle()}.");
        }
        if ($this->plainText($sheet, 'A18') !== 'к декабрю предыдущего года'
            || $this->plainText($sheet, 'A19') !== 'декабрь'
        ) {
            $this->fail('unexpected_previous_december_block', "Unexpected previous-December block in sheet {$sheet->getTitle()}.");
        }

        foreach (self::MONTHS as $offset => $month) {
            $row = $offset + 6;
            if ($this->plainText($sheet, "A{$row}") !== $month) {
                $this->fail('unexpected_month_sequence', "Unexpected CPI month sequence in sheet {$sheet->getTitle()}.");
            }
        }

        $states = [];
        foreach ($years as $yearOffset => $year) {
            $columnIndex = $yearOffset + 2;
            $column = Coordinate::stringFromColumnIndex($columnIndex);

            foreach (self::MONTHS as $monthOffset => $month) {
                $row = $monthOffset + 6;
                $address = "{$column}{$row}";
                $cell = $sheet->getCell($address);
                $value = $cell->getValue();

                if ($this->isBlank($value)) {
                    $states[] = null;

                    continue;
                }
                if ($cell->getDataType() !== DataType::TYPE_NUMERIC
                    || (! is_int($value) && ! is_float($value))
                    || (is_float($value) && ! is_finite($value))
                ) {
                    $this->fail('unsupported_observation_value', "Unsupported CPI value in {$sheet->getTitle()}!{$address}.");
                }

                $sourceRaw = (string) $value;
                if (preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $sourceRaw) !== 1) {
                    $this->fail('unsupported_numeric_representation', "Unsupported CPI numeric representation in {$sheet->getTitle()}!{$address}.");
                }

                $states[] = new ParsedConsumerPriceIndexObservation(
                    sprintf('%04d-%02d-01', $year, $monthOffset + 1),
                    $sourceRaw,
                    $sourceRaw,
                    $sheet->getTitle(),
                    $row,
                    $column,
                    $address,
                );
            }
        }

        $lastPublishedIndex = null;
        foreach ($states as $index => $state) {
            if ($state !== null) {
                $lastPublishedIndex = $index;
            }
        }
        if ($lastPublishedIndex === null) {
            $this->fail('no_observations', "CPI sheet {$sheet->getTitle()} contains no supported observations.");
        }

        $observations = [];
        foreach ($states as $index => $state) {
            if ($index <= $lastPublishedIndex && $state === null) {
                $this->fail('internal_observation_gap', "CPI sheet {$sheet->getTitle()} contains an internal blank period.");
            }
            if ($state !== null) {
                $observations[] = $state;
            }
        }

        $denominationText = $this->plainText($sheet, 'A20');
        if (preg_match(self::DENOMINATION_NOTE_PATTERN, $denominationText, $matches) !== 1) {
            $this->fail('missing_denomination_note', "The January 1998 denomination note is missing from sheet {$sheet->getTitle()}.");
        }
        $territorialNote = $this->plainText($sheet, 'A22');
        if ($territorialNote !== self::TERRITORIAL_NOTE) {
            $this->fail('unexpected_territorial_note', "The territorial coverage note changed in sheet {$sheet->getTitle()}.");
        }

        return [
            'series' => new ParsedConsumerPriceIndexSeries(
                $category['key'],
                $category['name'],
                $sheet->getTitle(),
                $observations,
            ),
            'years' => $years,
            'denomination_note' => $matches[0],
            'territorial_note' => $territorialNote,
            'trailing_blank_count' => count($states) - $lastPublishedIndex - 1,
        ];
    }

    /**
     * @param  list<int>  $years
     * @return array{sheet: string, key: string, name: string, title_fragment: string, contents_number: string}
     */
    private function resolveCategory(string $title, array $years): array
    {
        $firstYear = $years[0];
        $lastYear = $years[array_key_last($years)];

        foreach (self::CATEGORIES as $sheetName => $category) {
            $definitionMarker = $sheetName === '01' ? '1)' : '';
            $expected = "Индексы потребительских цен на {$category['title_fragment']}{$definitionMarker} по Российской Федерации в {$firstYear}-{$lastYear}*)гг.";
            if ($title === $expected) {
                return ['sheet' => $sheetName, ...$category];
            }
        }

        $this->fail('unexpected_aggregate_title', 'The CPI aggregate title does not match an exact supported category identity.');
    }

    /** @return list<int> */
    private function parseYears(Worksheet $sheet): array
    {
        $years = [];
        $firstBlankColumn = null;

        for ($columnIndex = 2; $columnIndex <= 257; $columnIndex++) {
            $value = $sheet->getCell([$columnIndex, 4])->getValue();
            if ($this->isBlank($value)) {
                $firstBlankColumn = $columnIndex;
                break;
            }
            if ((! is_int($value) && ! is_float($value))
                || (int) $value != $value
            ) {
                $this->fail('unexpected_year_value', "CPI year columns are invalid in sheet {$sheet->getTitle()}.");
            }

            $year = (int) $value;
            $expected = 1991 + count($years);
            if ($year !== $expected) {
                $this->fail('unexpected_year_sequence', "CPI year sequence is invalid in sheet {$sheet->getTitle()}.");
            }
            $years[] = $year;
        }

        if ($years === [] || $firstBlankColumn === null) {
            $this->fail('unexpected_year_sequence', "CPI year columns are missing or exceed the supported structural bound in sheet {$sheet->getTitle()}.");
        }

        foreach ($sheet->getCellCollection()->getCoordinates() as $coordinate) {
            if (preg_match('/^([A-Z]+)4$/D', $coordinate, $matches) !== 1) {
                continue;
            }
            $columnIndex = Coordinate::columnIndexFromString($matches[1]);
            if ($columnIndex >= $firstBlankColumn
                && ! $this->isBlank($sheet->getCell($coordinate)->getValue())
            ) {
                $this->fail('unexpected_year_after_gap', "CPI year data continues after a blank column in sheet {$sheet->getTitle()}.");
            }
        }

        return $years;
    }

    /** @param list<ParsedConsumerPriceIndexSeries> $series */
    private function validateSharedCoverage(array $series): void
    {
        if (count($series) !== 4) {
            $this->fail('unexpected_series_count', 'The CPI snapshot must contain exactly four aggregate series.');
        }

        $firstPeriod = $series[0]->firstPeriod();
        $lastPeriod = $series[0]->lastPeriod();
        $count = count($series[0]->observations);

        foreach ($series as $candidate) {
            if ($candidate->firstPeriod() !== $firstPeriod
                || $candidate->lastPeriod() !== $lastPeriod
                || count($candidate->observations) !== $count
            ) {
                $this->fail('inconsistent_series_coverage', 'CPI aggregate series do not share one continuous coverage range.');
            }
        }
    }

    /** @param list<int> $years */
    private function validateContentsSheet(Worksheet $sheet, array $years): void
    {
        if ($this->plainText($sheet, 'A1') !== 'Содержание:') {
            $this->fail('unexpected_contents_heading', 'The CPI contents heading changed.');
        }
        if ($years === []) {
            $this->fail('unexpected_year_sequence', 'The CPI workbook contains no shared year sequence.');
        }

        $firstYear = $years[0];
        $lastYear = $years[array_key_last($years)];
        foreach (array_values(self::CATEGORIES) as $offset => $category) {
            $row = $offset + 4;
            $expected = "{$category['contents_number']} Индексы потребительских цен на {$category['title_fragment']} по Российской Федерации в {$firstYear}-{$lastYear} гг.";
            if ($this->plainText($sheet, "A{$row}") !== $expected) {
                $this->fail('unexpected_contents_entry', 'The CPI contents aggregate list changed.');
            }
        }
    }

    private function plainText(Worksheet $sheet, string $address): string
    {
        $value = $sheet->getCell($address)->getValue();

        return $value instanceof RichText ? $value->getPlainText() : (string) ($value ?? '');
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    private function fail(string $code, string $message): never
    {
        throw new StatisticalImportParsingFailed($code, $message);
    }
}
