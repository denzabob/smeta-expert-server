<?php

namespace Tests\Feature\Import;

use App\Utilities\SpreadsheetReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SpreadsheetReaderXlsxCompatibilityTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_reader_keeps_metadata_preview_formula_empty_cells_and_multi_row_iteration(): void
    {
        $reader = new SpreadsheetReader($this->workbookPath(), 'xlsx');

        $metadata = $reader->getMetadata();
        $preview = $reader->readPreview(2);
        $rows = iterator_to_array($reader->iterateRows(1));

        $this->assertSame([['index' => 0, 'name' => 'Данные']], $metadata['sheets']);
        $this->assertSame(4, $metadata['column_count']);
        $this->assertSame(['Материал', 'Количество', 'Итог', 'Пусто'], array_column($preview['columns'], 'name_guess'));
        $this->assertSame(['Материал 2', 2, 4, null], $preview['rows'][1]);
        $this->assertCount(250, $rows);
        $this->assertSame(['Материал 2', 2, 4, null], $rows[1]);
        $this->assertSame(['Материал 251', 251, 502, null], $rows[250]);
    }

    private function workbookPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'spreadsheet-reader-');
        $this->assertNotFalse($path);
        $this->temporaryPaths[] = $path;

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Данные');
        $sheet->fromArray([['Материал', 'Количество', 'Итог', 'Пусто']]);

        for ($row = 2; $row <= 251; $row++) {
            $sheet->setCellValue("A{$row}", "Материал {$row}");
            $sheet->setCellValue("B{$row}", $row);
            $sheet->setCellValue("C{$row}", "=B{$row}*2");
        }

        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }
}
