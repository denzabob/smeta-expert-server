<?php

namespace Tests\Feature\PriceIndices\Support;

use App\Domain\PriceIndices\Domain\Datasets\StatisticalDataset;
use App\Domain\PriceIndices\Domain\Enums\SourceFileStatus;
use App\Domain\PriceIndices\Domain\Indicators\StatisticalIndicator;
use App\Domain\PriceIndices\Domain\SourceFiles\StatisticalSourceFile;
use App\Domain\PriceIndices\Domain\Territories\StatisticalTerritory;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

trait BuildsStatisticalImportWorkbook
{
    protected string $importTestDisk = 'price_indices_import_test';

    protected function createReferenceDataset(): StatisticalDataset
    {
        $dataset = StatisticalDataset::factory()->create(['code' => 'producer_price_indices_by_product']);
        StatisticalIndicator::factory()->create([
            'dataset_id' => $dataset->id,
            'code' => 'producer_price_index',
            'name' => 'Индекс цен производителей',
            'data_kind' => 'index',
        ]);
        StatisticalTerritory::factory()->create([
            'code' => 'RU',
            'name' => 'Российская Федерация',
            'normalized_name' => 'российская федерация',
            'type' => 'country',
        ]);

        return $dataset;
    }

    protected function sourceFileForWorkbook(StatisticalDataset $dataset, string $path): StatisticalSourceFile
    {
        return StatisticalSourceFile::factory()->create([
            'dataset_id' => $dataset->id,
            'status' => SourceFileStatus::Active,
            'storage_disk' => $this->importTestDisk,
            'stored_path' => $path,
            'original_filename' => basename($path),
            'sha256' => hash_file('sha256', Storage::disk($this->importTestDisk)->path($path)),
        ]);
    }

    protected function writeRepresentativeWorkbook(string $path = 'representative.xlsx'): string
    {
        return $this->writeWorkbook($path, function (Spreadsheet $book): void {
            $flat = $book->getActiveSheet();
            $flat->setTitle('flat-2021');
            $this->headers($flat, 'Индексы Российской Федерации 2021 к предыдущему месяцу', ['январь', 'февраль']);
            $flat->fromArray(['Наборы кухонной мебели', '31.02.10.140', 109.51, 100.0], null, 'A5');
            $flat->fromArray(['Базовый товар', '05.10.10.101', '97,511)', '…'], null, 'A6');
            $flat->fromArray(['Локальный товар', '05.10.10.101.АГ', 98, 99], null, 'A7');

            $ignored = $book->createSheet();
            $ignored->setTitle('ignored-2021');
            $this->headers($ignored, 'Индексы Российской Федерации 2021 к декабрю предыдущего года', ['январь', 'февраль']);
            $ignored->fromArray(['Наборы кухонной мебели', '31.02.10.140', 999, 999], null, 'A5');

            $regional = $book->createSheet();
            $regional->setTitle('regional-2024');
            $this->headers($regional, 'Индексы цен производителей 2024 к предыдущему месяцу', ['январь', 'февраль']);
            $regional->fromArray(['Наборы кухонной мебели', '31.02.10.140'], null, 'A5');
            $regional->fromArray(['Российская Федерация', null, 106.81, 100.19], null, 'A6');
            $regional->fromArray(['Москва', null, 110, 111], null, 'A7');
            $regional->fromArray(['Базовый товар', '05.10.10.101'], null, 'A8');
            $regional->fromArray(['Российская Федерация', null, 101, 102], null, 'A9');
            $regional->fromArray(['Локальный товар', '05.10.10.101.АГ'], null, 'A10');
            $regional->fromArray(['Российская Федерация', null, 103, 104], null, 'A11');

            $partial = $book->createSheet();
            $partial->setTitle('regional-2026');
            $this->headers($partial, 'Индексы цен производителей 2026 к предыдущему месяцу', ['январь', 'февраль']);
            $partial->fromArray(['Наборы кухонной мебели', '31.02.10.140'], null, 'A5');
            $partial->fromArray(['Российская Федерация', null, 109.24, 99.99], null, 'A6');
        });
    }

    protected function writeFormulaWorkbook(string $path = 'formula.xlsx'): string
    {
        return $this->writeWorkbook($path, function (Spreadsheet $book): void {
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('formula-2021');
            $this->headers($sheet, 'Индексы Российской Федерации 2021 к предыдущему месяцу', ['январь', 'февраль']);
            $sheet->fromArray(['Наборы кухонной мебели', '31.02.10.140', 100], null, 'A5');
            $sheet->setCellValue('D5', '=100+1');
        });
    }

    protected function writeMissingRuWorkbook(string $path = 'missing-ru.xlsx'): string
    {
        return $this->writeWorkbook($path, function (Spreadsheet $book): void {
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('regional-2024');
            $this->headers($sheet, 'Индексы цен производителей 2024 к предыдущему месяцу', ['январь']);
            $sheet->fromArray(['Первый товар', '10.11.12'], null, 'A5');
            $sheet->fromArray(['Москва', null, 101], null, 'A6');
            $sheet->fromArray(['Второй товар', '10.11.13'], null, 'A7');
            $sheet->fromArray(['Российская Федерация', null, 102], null, 'A8');
        });
    }

    private function writeWorkbook(string $path, callable $builder): string
    {
        $book = new Spreadsheet();
        $builder($book);
        $absolutePath = Storage::disk($this->importTestDisk)->path($path);
        (new Xlsx($book))->save($absolutePath);
        $book->disconnectWorksheets();

        return $path;
    }

    private function headers($sheet, string $title, array $months): void
    {
        $sheet->setCellValue('A1', $title);
        foreach ($months as $index => $month) {
            $column = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 3);
            $sheet->setCellValue("{$column}4", $month);
            $sheet->getStyle("{$column}5:{$column}20")->getNumberFormat()->setFormatCode('0.00');
        }
    }
}
