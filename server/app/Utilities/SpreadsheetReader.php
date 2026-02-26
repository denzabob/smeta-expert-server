<?php

namespace App\Utilities;

use Generator;
use RuntimeException;

/**
 * Utility class for reading spreadsheet files (XLSX, XLS, CSV).
 * Uses memory-efficient streaming where possible.
 */
class SpreadsheetReader
{
    private string $filePath;
    private string $fileType;
    private array $options;

    /**
     * @param string $filePath Full path to the file
     * @param string $fileType File type: xlsx, xls, csv
     * @param array $options Reader options (encoding, delimiter for CSV)
     */
    public function __construct(string $filePath, string $fileType, array $options = [])
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("File not found: {$filePath}");
        }

        $this->filePath = $filePath;
        $this->fileType = strtolower($fileType);
        $this->options = array_merge([
            'csv_encoding' => 'UTF-8',
            'csv_delimiter' => ',',
            'sheet_index' => 0,
        ], $options);
    }

    /**
     * Get metadata about the file (sheet count, etc.).
     *
     * @return array{sheets: array, column_count: int}
     */
    public function getMetadata(): array
    {
        if ($this->fileType === 'csv') {
            return [
                'sheets' => [['index' => 0, 'name' => 'Sheet1']],
                'column_count' => $this->getCsvColumnCount(),
            ];
        }

        return $this->getExcelMetadata();
    }

    /**
     * Read a preview of rows from the file.
     *
     * @param int $maxRows Maximum number of rows to read
     * @param int $sheetIndex Sheet index for Excel files (0-based)
     * @return array{columns: array, rows: array}
     */
    public function readPreview(int $maxRows = 20, int $sheetIndex = 0): array
    {
        $this->options['sheet_index'] = $sheetIndex;

        if ($this->fileType === 'csv') {
            return $this->readCsvPreview($maxRows);
        }

        return $this->readExcelPreview($maxRows, $sheetIndex);
    }

    /**
     * Iterate over all rows in the file (streaming).
     *
     * @param int $startRow Row index to start from (0-based)
     * @param int $sheetIndex Sheet index for Excel files (0-based)
     * @return Generator<int, array> Yields [rowIndex => rowData]
     */
    public function iterateRows(int $startRow = 0, int $sheetIndex = 0): Generator
    {
        $this->options['sheet_index'] = $sheetIndex;

        if ($this->fileType === 'csv') {
            yield from $this->iterateCsvRows($startRow);
        } else {
            yield from $this->iterateExcelRows($startRow, $sheetIndex);
        }
    }

    /**
     * Get column count from CSV file.
     */
    private function getCsvColumnCount(): int
    {
        $handle = $this->openCsvFile();
        $firstRow = fgetcsv($handle, 0, $this->options['csv_delimiter']);
        fclose($handle);

        return $firstRow ? count($firstRow) : 0;
    }

    /**
     * Read CSV preview.
     */
    private function readCsvPreview(int $maxRows): array
    {
        $handle = $this->openCsvFile();
        $rows = [];
        $columns = [];
        $rowIndex = 0;

        while (($row = fgetcsv($handle, 0, $this->options['csv_delimiter'])) !== false && $rowIndex < $maxRows) {
            // Convert encoding if needed
            if (strtoupper($this->options['csv_encoding']) !== 'UTF-8') {
                $row = array_map(function ($cell) {
                    return mb_convert_encoding($cell, 'UTF-8', $this->options['csv_encoding']);
                }, $row);
            }

            // Build column info from first row
            if ($rowIndex === 0 && empty($columns)) {
                $columns = array_map(function ($index, $value) {
                    return [
                        'index' => $index,
                        'name_guess' => is_string($value) ? trim($value) : '',
                    ];
                }, array_keys($row), $row);
            }

            $rows[] = $row;
            $rowIndex++;
        }

        fclose($handle);

        return [
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * Iterate over CSV rows.
     */
    private function iterateCsvRows(int $startRow): Generator
    {
        $handle = $this->openCsvFile();
        $rowIndex = 0;

        while (($row = fgetcsv($handle, 0, $this->options['csv_delimiter'])) !== false) {
            if ($rowIndex >= $startRow) {
                // Convert encoding if needed
                if (strtoupper($this->options['csv_encoding']) !== 'UTF-8') {
                    $row = array_map(function ($cell) {
                        return mb_convert_encoding($cell, 'UTF-8', $this->options['csv_encoding']);
                    }, $row);
                }

                yield $rowIndex => $row;
            }
            $rowIndex++;
        }

        fclose($handle);
    }

    /**
     * Open CSV file with proper handling.
     *
     * @return resource
     */
    private function openCsvFile()
    {
        $handle = fopen($this->filePath, 'r');
        if ($handle === false) {
            throw new RuntimeException("Cannot open file: {$this->filePath}");
        }

        // Skip BOM if present
        $bom = fread($handle, 3);
        if ($bom !== "\xef\xbb\xbf") {
            rewind($handle);
        }

        return $handle;
    }

    /**
     * Get Excel file metadata using PhpSpreadsheet.
     */
    private function getExcelMetadata(): array
    {
        $reader = $this->createExcelReader();
        $reader->setReadDataOnly(true);
        
        // Get sheet names without loading full file
        $spreadsheet = $reader->load($this->filePath);
        $sheetNames = $spreadsheet->getSheetNames();
        
        // Get column count from first sheet
        $sheet = $spreadsheet->getSheet($this->options['sheet_index']);
        $columnCount = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString(
            $sheet->getHighestColumn()
        );

        $sheets = array_map(function ($name, $index) {
            return ['index' => $index, 'name' => $name];
        }, $sheetNames, array_keys($sheetNames));

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'sheets' => $sheets,
            'column_count' => $columnCount,
        ];
    }

    /**
     * Read Excel preview.
     */
    private function readExcelPreview(int $maxRows, int $sheetIndex): array
    {
        $reader = $this->createExcelReader();
        $reader->setReadDataOnly(true);

        // Use chunk reading for efficiency
        $chunkFilter = new ChunkReadFilter(1, $maxRows);
        $reader->setReadFilter($chunkFilter);

        $spreadsheet = $reader->load($this->filePath);
        $sheet = $spreadsheet->getSheet($sheetIndex);

        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        $rows = [];
        $columns = [];

        foreach ($sheet->getRowIterator(1, min($maxRows, $sheet->getHighestRow())) as $rowIndex => $row) {
            $rowData = [];
            $cellIterator = $row->getCellIterator('A', $highestColumn);
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $colIndex => $cell) {
                $value = $cell->getValue();
                // Handle formulas - get calculated value
                if ($cell->isFormula()) {
                    try {
                        $value = $cell->getCalculatedValue();
                    } catch (\Exception $e) {
                        $value = $cell->getValue();
                    }
                }
                $rowData[] = $value;
            }

            // Build column info from first row
            if ($rowIndex === 1 && empty($columns)) {
                $columns = array_map(function ($index, $value) {
                    return [
                        'index' => $index,
                        'name_guess' => is_string($value) ? trim($value) : '',
                    ];
                }, array_keys($rowData), $rowData);
            }

            $rows[] = $rowData;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return [
            'columns' => $columns,
            'rows' => $rows,
        ];
    }

    /**
     * Iterate over Excel rows.
     */
    private function iterateExcelRows(int $startRow, int $sheetIndex): Generator
    {
        $reader = $this->createExcelReader();
        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($this->filePath);
        $sheet = $spreadsheet->getSheet($sheetIndex);

        $highestColumn = $sheet->getHighestColumn();
        $highestRow = $sheet->getHighestRow();

        // Convert to 1-based row index for Excel
        $excelStartRow = $startRow + 1;

        foreach ($sheet->getRowIterator($excelStartRow, $highestRow) as $rowIndex => $row) {
            $rowData = [];
            $cellIterator = $row->getCellIterator('A', $highestColumn);
            $cellIterator->setIterateOnlyExistingCells(false);

            foreach ($cellIterator as $cell) {
                $value = $cell->getValue();
                if ($cell->isFormula()) {
                    try {
                        $value = $cell->getCalculatedValue();
                    } catch (\Exception $e) {
                        $value = $cell->getValue();
                    }
                }
                $rowData[] = $value;
            }

            // Yield with 0-based index
            yield ($rowIndex - 1) => $rowData;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    /**
     * Create the appropriate Excel reader based on file type.
     */
    private function createExcelReader(): \PhpOffice\PhpSpreadsheet\Reader\IReader
    {
        if ($this->fileType === 'xlsx') {
            return new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        }

        if ($this->fileType === 'xls') {
            return new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        }

        throw new RuntimeException("Unsupported file type: {$this->fileType}");
    }
}

/**
 * Chunk read filter for PhpSpreadsheet to limit memory usage.
 */
class ChunkReadFilter implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter
{
    private int $startRow;
    private int $endRow;

    public function __construct(int $startRow, int $chunkSize)
    {
        $this->startRow = $startRow;
        $this->endRow = $startRow + $chunkSize - 1;
    }

    public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
    {
        return $row >= $this->startRow && $row <= $this->endRow;
    }
}
