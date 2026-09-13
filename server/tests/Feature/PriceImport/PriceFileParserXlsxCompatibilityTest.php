<?php

namespace Tests\Feature\PriceImport;

use App\Models\PriceImportSession;
use App\Services\PriceImport\ParsingException;
use App\Services\PriceImport\PriceFileParser;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class PriceFileParserXlsxCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_parser_preserves_existing_workbook_sheet_number_date_empty_cell_and_formula_behavior(): void
    {
        $session = $this->storeWorkbook('price-import/compatibility.xlsx');

        $result = app(PriceFileParser::class)->parse($session);
        $sheetList = app(PriceFileParser::class)->getSheetList((string) $session->getStoragePath());

        $this->assertSame(['Наименование', 'Цена', 'Дата', 'Пусто', 'Итог'], $result['headers']);
        $this->assertSame('ЛДСП', $result['rows'][1][0]);
        $this->assertSame(125.5, $result['rows'][1][1]);
        $this->assertSame(45658, $result['rows'][1][2]);
        $this->assertNull($result['rows'][1][3]);
        $this->assertSame(251.0, $result['rows'][1][4]);
        $this->assertSame(['Прайс', 'Дополнительно'], $result['sheet_names']);
        $this->assertSame([
            ['index' => 0, 'name' => 'Прайс', 'row_count' => 2],
            ['index' => 1, 'name' => 'Дополнительно', 'row_count' => 1],
        ], $sheetList);
    }

    public function test_parser_keeps_malformed_xlsx_as_a_controlled_parsing_failure(): void
    {
        Storage::disk('local')->put('price-import/malformed.xlsx', "PK\x03\x04corrupt");
        $session = $this->priceImportSession('price-import/malformed.xlsx');

        try {
            app(PriceFileParser::class)->parse($session);
            $this->fail('Expected malformed XLSX parsing to fail.');
        } catch (ParsingException $exception) {
            $this->assertStringStartsWith('Failed to parse Excel file:', $exception->getMessage());
        }
    }

    private function storeWorkbook(string $path): PriceImportSession
    {
        Storage::disk('local')->makeDirectory('price-import');
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Прайс');
        $sheet->fromArray([
            ['Наименование', 'Цена', 'Дата', 'Пусто', 'Итог'],
            ['ЛДСП', 125.5, 45658, null, '=B2*2'],
        ]);
        $second = $book->createSheet();
        $second->setTitle('Дополнительно');
        $second->setCellValue('A1', 'Примечание');

        (new Xlsx($book))->save(Storage::disk('local')->path($path));
        $book->disconnectWorksheets();

        return $this->priceImportSession($path);
    }

    private function priceImportSession(string $path): PriceImportSession
    {
        return new PriceImportSession([
            'file_path' => $path,
            'storage_disk' => 'local',
            'file_type' => PriceImportSession::FILE_TYPE_XLSX,
            'header_row_index' => 0,
            'sheet_index' => 0,
            'options' => [],
        ]);
    }
}
