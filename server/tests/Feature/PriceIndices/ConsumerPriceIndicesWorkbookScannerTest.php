<?php

namespace Tests\Feature\PriceIndices;

use App\Domain\PriceIndices\Application\Data\ParsedConsumerPriceIndexSnapshot;
use App\Domain\PriceIndices\Domain\Exceptions\StatisticalImportParsingFailed;
use App\Domain\PriceIndices\Infrastructure\Import\ConsumerPriceIndicesWorkbookScanner;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Feature\PriceIndices\Support\BuildsConsumerPriceIndexWorkbook;
use Tests\TestCase;

class ConsumerPriceIndicesWorkbookScannerTest extends TestCase
{
    use BuildsConsumerPriceIndexWorkbook;

    protected function tearDown(): void
    {
        $this->cleanupConsumerPriceIndexWorkbooks();

        parent::tearDown();
    }

    public function test_confirmed_workbook_grammar_produces_four_exact_dataset_local_series(): void
    {
        $snapshot = $this->scanner()->scan($this->writeConsumerPriceIndexWorkbook());

        $this->assertSame([
            'all_items_and_services',
            'food_products',
            'non_food_products',
            'services',
        ], array_column($snapshot->series, 'internalKey'));
        $this->assertSame([
            'Товары и услуги',
            'Продовольственные товары',
            'Непродовольственные товары',
            'Услуги',
        ], array_column($snapshot->series, 'name'));
        $this->assertSame('1991-01-01', $snapshot->firstPeriod());
        $this->assertSame('1992-07-01', $snapshot->lastPeriod());
        $this->assertSame([
            'all_items_and_services' => 19,
            'food_products' => 19,
            'non_food_products' => 19,
            'services' => 19,
        ], $snapshot->observationsPerSeries());
        $this->assertSame(76, $snapshot->totalObservations());
        $this->assertSame('101.01', $snapshot->series[0]->observations[0]->value);
        $this->assertSame('101.01', $snapshot->series[0]->observations[0]->sourceValueRaw);
        $this->assertSame('01', $snapshot->series[0]->observations[0]->sheetName);
        $this->assertSame('B6', $snapshot->series[0]->observations[0]->sourceCellAddress);
        $this->assertSame('1992-07-01', $snapshot->series[0]->observations[18]->periodStart);
        $this->assertNotContains('1992-08-01', array_column($snapshot->series[0]->observations, 'periodStart'));
        $this->assertNotContains('B19', array_column($snapshot->series[0]->observations, 'sourceCellAddress'));
        $this->assertSame([
            'january_1998_denomination',
            'territorial_coverage_2026',
        ], array_column($snapshot->sourceNotes, 'code'));
        $this->assertSame([
            'previous_december_excluded',
            'trailing_blank_periods_excluded',
        ], array_column($snapshot->warnings, 'code'));

        $identity = implode('|', [
            ParsedConsumerPriceIndexSnapshot::DATASET_CODE,
            ParsedConsumerPriceIndexSnapshot::CLASSIFIER_CODE,
            ...array_column($snapshot->series, 'internalKey'),
        ]);
        $this->assertStringNotContainsString('okpd2', strtolower($identity));
        $this->assertStringNotContainsString('kipc', strtolower($identity));
    }

    public function test_unexpected_aggregate_title_is_rejected(): void
    {
        $path = $this->writeConsumerPriceIndexWorkbook(
            fn (Spreadsheet $book) => $book->getSheetByName('02')?->setCellValue('A1', 'Изменённый заголовок'),
        );

        $this->assertParsingFailure('unexpected_aggregate_title', fn () => $this->scanner()->scan($path));
    }

    public function test_sheet_number_is_only_a_structural_invariant_after_exact_title_mapping(): void
    {
        $path = $this->writeConsumerPriceIndexWorkbook(function (Spreadsheet $book): void {
            $food = $book->getSheetByName('02');
            $nonFood = $book->getSheetByName('03');
            self::assertNotNull($food);
            self::assertNotNull($nonFood);
            $foodTitle = $food->getCell('A1')->getValue();
            $food->setCellValue('A1', $nonFood->getCell('A1')->getValue());
            $nonFood->setCellValue('A1', $foodTitle);
        });

        $this->assertParsingFailure('aggregate_sheet_mismatch', fn () => $this->scanner()->scan($path));
    }

    public function test_required_sheet_is_mandatory(): void
    {
        $path = $this->writeConsumerPriceIndexWorkbook(function (Spreadsheet $book): void {
            $sheet = $book->getSheetByName('03');
            self::assertNotNull($sheet);
            $book->removeSheetByIndex($book->getIndex($sheet));
        });

        $this->assertParsingFailure('missing_required_sheet', fn () => $this->scanner()->scan($path));
    }

    public function test_previous_month_heading_is_exact(): void
    {
        $path = $this->writeConsumerPriceIndexWorkbook(
            fn (Spreadsheet $book) => $book->getSheetByName('01')?->setCellValue('A5', 'к предыдущему месяцу'),
        );

        $this->assertParsingFailure('unexpected_comparison_basis', fn () => $this->scanner()->scan($path));
    }

    public function test_year_and_month_sequences_are_strict(): void
    {
        $wrongYear = $this->writeConsumerPriceIndexWorkbook(
            fn (Spreadsheet $book) => $book->getSheetByName('04')?->setCellValue('C4', 1993),
        );
        $this->assertParsingFailure('unexpected_year_sequence', fn () => $this->scanner()->scan($wrongYear));

        $wrongMonth = $this->writeConsumerPriceIndexWorkbook(
            fn (Spreadsheet $book) => $book->getSheetByName('04')?->setCellValue('A7', 'Февраль'),
        );
        $this->assertParsingFailure('unexpected_month_sequence', fn () => $this->scanner()->scan($wrongMonth));
    }

    public function test_internal_blank_is_rejected_but_trailing_blanks_are_excluded(): void
    {
        $valid = $this->scanner()->scan($this->writeConsumerPriceIndexWorkbook());
        $this->assertSame('1992-07-01', $valid->lastPeriod());

        $gap = $this->writeConsumerPriceIndexWorkbook(
            fn (Spreadsheet $book) => $book->getSheetByName('03')?->setCellValue('B7', null),
        );
        $this->assertParsingFailure('internal_observation_gap', fn () => $this->scanner()->scan($gap));
    }

    public function test_unknown_string_in_supported_block_is_rejected(): void
    {
        $path = $this->writeConsumerPriceIndexWorkbook(
            fn (Spreadsheet $book) => $book->getSheetByName('01')?->setCellValue('B8', 'н/д'),
        );

        $this->assertParsingFailure('unsupported_observation_value', fn () => $this->scanner()->scan($path));
    }

    public function test_formula_in_supported_block_is_rejected(): void
    {
        $path = $this->writeConsumerPriceIndexWorkbook(
            fn (Spreadsheet $book) => $book->getSheetByName('01')?->setCellValue('B8', '=100+1'),
        );

        $this->assertParsingFailure('unsupported_observation_value', fn () => $this->scanner()->scan($path));
    }

    public function test_previous_december_values_are_ignored_without_becoming_observations(): void
    {
        $snapshot = $this->scanner()->scan($this->writeConsumerPriceIndexWorkbook());

        foreach ($snapshot->series as $series) {
            $this->assertNotContains(18, array_column($series->observations, 'sourceRow'));
            $this->assertNotContains(19, array_column($series->observations, 'sourceRow'));
        }
    }

    public function test_exact_operator_artifact_contract_when_path_is_available(): void
    {
        $path = getenv('PRICE_INDICES_CPI_ARTIFACT_PATH');
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            $this->markTestSkipped('Operator CPI artifact path is not available.');
        }

        $this->assertSame(37871, filesize($path));
        $this->assertSame(
            'ACE8990FE8358173F743987A256EAEF71501B06B5C4E5FE865B28046776EA412',
            strtoupper(hash_file('sha256', $path)),
        );

        $snapshot = $this->scanner()->scan($path);

        $this->assertCount(4, $snapshot->series);
        $this->assertSame('1991-01-01', $snapshot->firstPeriod());
        $this->assertSame('2026-07-01', $snapshot->lastPeriod());
        $this->assertSame([427, 427, 427, 427], array_values($snapshot->observationsPerSeries()));
        $this->assertSame(1708, $snapshot->totalObservations());
        $this->assertSame('100.54', $snapshot->series[0]->observations[426]->value);
        $this->assertSame('100.54', $snapshot->series[0]->observations[426]->sourceValueRaw);
    }

    private function scanner(): ConsumerPriceIndicesWorkbookScanner
    {
        return app(ConsumerPriceIndicesWorkbookScanner::class);
    }

    private function assertParsingFailure(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail("Expected CPI parsing failure {$code}.");
        } catch (StatisticalImportParsingFailed $exception) {
            $this->assertSame($code, $exception->failureCode);
        }
    }
}
